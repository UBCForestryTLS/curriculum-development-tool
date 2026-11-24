import base64
import hashlib
import json
import re
from datetime import UTC, datetime
from uuid import UUID

import boto3
from boto3.dynamodb.conditions import Attr, Key
from botocore.config import Config

from app.config import get_settings
from app.job_models import MappingJobRequest


class MappingJobConflictError(ValueError):
    """The job ID is already being used for different mapping inputs."""


class InvalidResultsCursorError(ValueError):
    """The results cursor is malformed or belongs to a different job."""


class MappingJobStore:
    """Store job summaries and individual CLO-PLO pairs in the same DynamoDB table."""

    def __init__(self, table=None, *, config: Config | None = None):
        """Use the configured DynamoDB table, or a supplied table for tests."""
        if table is None:
            settings = get_settings()
            # This points to an existing table; it does not create one.
            table = boto3.resource("dynamodb", region_name=settings.aws_region, config=config).Table(
                settings.dynamodb_table_name
            )
        self.table = table

    @staticmethod
    def _key(job_id: UUID | str) -> dict[str, str]:
        """Build the summary key. Pair records share this pk but use their own sk values."""
        return {"pk": f"JOB#{UUID(str(job_id))}", "sk": "METADATA"}

    def create(self, request: MappingJobRequest) -> dict[str, object]:
        """Create the job and all pairs, or resume creation for the same job and inputs."""
        job_id = str(request.job_id)
        # Fingerprint the inputs so retries can reuse a job only when the work is unchanged.
        input_json = json.dumps(request.model_dump(mode="json"), sort_keys=True, ensure_ascii=False)
        input_hash = hashlib.sha256(input_json.encode("utf-8")).hexdigest()
        record = {
            **self._key(job_id),
            # Index keys for looking up jobs by course or program.
            "GSI1PK": f"COURSE#{request.course_id}",
            "GSI1SK": f"JOB#{job_id}",
            "GSI2PK": f"PROGRAM#{request.program_id}",
            "GSI2SK": f"JOB#{job_id}",
            "job_id": job_id,
            "course_id": request.course_id,
            "program_id": request.program_id,
            "total_clos": len(request.course_learning_outcomes),
            "total_plos": len(request.program_learning_outcomes),
            "total_pairs": len(request.course_learning_outcomes) * len(request.program_learning_outcomes),
            # Successfully processed pairs can be unmapped, so keep this count separate.
            "processed_pairs": 0,
            "mapped_pairs": 0,
            "errored_pairs": 0,
            "status": "pending",
            "created_at": datetime.now(UTC).isoformat(),
            # Save the allowed levels once so later processing uses the original definitions.
            "mapping_scale_options": [scale.model_dump() for scale in request.mapping_scales],
            "input_hash": input_hash,
            "pairs_ready": False,
        }

        try:
            self.table.put_item(Item=record, ConditionExpression=Attr("pk").not_exists())
        except self.table.meta.client.exceptions.ConditionalCheckFailedException:
            record = self.get(job_id)
            
            if record is None:
                raise
            if record["input_hash"] != input_hash:
                raise MappingJobConflictError("Job ID already exists with different inputs.") from None

        if record["pairs_ready"]:
            return record

        self._create_pairs(request)

        # Only make the job ready after every pair is saved. Processing must check this flag.
        # If creation stops early, retrying adds missing pairs without resetting existing ones.
        response = self.table.update_item(
            Key=self._key(job_id),
            UpdateExpression="SET pairs_ready = :ready",
            ExpressionAttributeValues={":ready": True},
            ConditionExpression=Attr("input_hash").eq(input_hash),
            ReturnValues="ALL_NEW",
        )
        return response["Attributes"]

    def _create_pairs(self, request: MappingJobRequest) -> None:
        """Save every CLO-PLO combination without replacing pairs already stored."""
        job_id = str(request.job_id)
        for clo in request.course_learning_outcomes:
            for plo in request.program_learning_outcomes:
                record = {
                    "pk": f"JOB#{job_id}",
                    "sk": f"LO_PAIR#{clo.l_outcome_id}-{plo.pl_outcome_id}",
                    "job_id": job_id,
                    "clo_id": clo.l_outcome_id,
                    "plo_id": plo.pl_outcome_id,
                    "clo_text": clo.l_outcome,
                    "plo_text": plo.pl_outcome,
                    "processed": False,
                    "error": None,
                    "mapping_scales": [],
                    "explanation": None,
                }
                try:
                    self.table.put_item(Item=record, ConditionExpression=Attr("pk").not_exists())
                except self.table.meta.client.exceptions.ConditionalCheckFailedException:
                    # Another creation attempt already saved this pair; keep its current state.
                    continue

    def get(self, job_id: UUID | str) -> dict[str, object] | None:
        """Read the latest job summary and progress, or None if the job does not exist."""
        response = self.table.get_item(Key=self._key(job_id), ConsistentRead=True)
        return response.get("Item")

    def get_pairs(self, job_id: UUID | str) -> list[dict[str, object]]:
        """Return all pairs for this job, including results once they have been saved."""
        # Query only this job's pair records and let the SDK fetch every page.
        paginator = self.table.meta.client.get_paginator("query")
        pages = paginator.paginate(
            TableName=self.table.name,
            KeyConditionExpression=Key("pk").eq(self._key(job_id)["pk"])
            & Key("sk").begins_with("LO_PAIR#"),
            ConsistentRead=True,
        )
        return [record for page in pages for record in page["Items"]]

    def get_results_page(
        self, job_id: UUID | str, *, limit: int = 50, cursor: str | None = None,
    ) -> dict[str, object]:
        """Read one page of saved results and errors, leaving pending pairs out."""
        if type(limit) is not int or not 1 <= limit <= 100:
            raise ValueError("Results limit must be an integer between 1 and 100.")
        job_key = self._key(job_id)
        query = {
            "KeyConditionExpression": Key("pk").eq(job_key["pk"])
            & Key("sk").begins_with("LO_PAIR#"),
            # Saved errors still have processed=False, but they are outcomes too.
            "FilterExpression": Attr("processed").eq(True) | Attr("error").ne(None),
            "ConsistentRead": True,
            "Limit": limit,
        }
        if cursor is not None:
            # Keep the cursor tied to this job and reject keys outside its pair records.
            try:
                start_key = json.loads(base64.b64decode(cursor, altchars=b"-_", validate=True))
                if (
                    not isinstance(start_key, dict)
                    or set(start_key) != {"pk", "sk"}
                    or start_key["pk"] != job_key["pk"]
                    or not isinstance(start_key["sk"], str)
                    or not re.fullmatch(r"LO_PAIR#[1-9][0-9]*-[1-9][0-9]*", start_key["sk"])
                ):
                    raise ValueError
            except ValueError:
                raise InvalidResultsCursorError("Invalid results cursor for this job.") from None
            query["ExclusiveStartKey"] = start_key

        # Read just one DynamoDB page. The limit is applied before filtering, so even
        # an empty results page can have a cursor that Laravel needs to follow.
        page = self.table.query(**query)
        next_cursor = None
        if page.get("LastEvaluatedKey"):
            next_cursor = base64.urlsafe_b64encode(
                json.dumps(page["LastEvaluatedKey"]).encode("utf-8")
            ).decode("ascii")
        return {"results": page["Items"], "next_cursor": next_cursor}

    def get_pending_pairs(self, job_id: UUID | str, *, limit: int) -> list[dict[str, object]]:
        """Return up to limit pairs without a saved result or error, from a ready job."""
        if type(limit) is not int or limit < 1:
            raise ValueError("Pair limit must be a positive integer.")
        job = self.get(job_id)
        if job is None or not job["pairs_ready"]:
            raise ValueError("Mapping job does not exist or is not ready.")
        if job["status"] not in ("pending", "in_progress"):
            return []

        paginator = self.table.meta.client.get_paginator("query")
        pages = paginator.paginate(
            TableName=self.table.name,
            KeyConditionExpression=Key("pk").eq(job["pk"]) & Key("sk").begins_with("LO_PAIR#"),
            # Failed checks have processed=False too, but should not be retried automatically.
            FilterExpression=Attr("processed").eq(False) & Attr("error").eq(None),
            ConsistentRead=True,
            # Filtering can leave empty pages. Keep looking until we have enough pending pairs.
            PaginationConfig={"PageSize": limit, "MaxItems": limit},
        )
        return [record for page in pages for record in page["Items"]]

    def save_pair_result(
        self,
        job_id: UUID | str,
        clo_id: int,
        plo_id: int,
        *,
        mapping_scale_ids: list[int],
        explanation: str,
    ) -> bool:
        """Save the chosen level IDs and explanation. An empty list means a valid no-match."""
        if not isinstance(mapping_scale_ids, list) or any(
            type(value) is not int for value in mapping_scale_ids
        ):
            raise ValueError("Mapping scale IDs must be a list of integers.")
        if len(set(mapping_scale_ids)) != len(mapping_scale_ids):
            raise ValueError("Mapping scale IDs must be unique.")
        if not isinstance(explanation, str) or not explanation.strip():
            raise ValueError("Mapping explanation must be nonblank text.")
        return self._save_pair_outcome(job_id, clo_id, plo_id, mapping_scale_ids, explanation, None)

    def save_pair_error(self, job_id: UUID | str, clo_id: int, plo_id: int, *, error: str) -> bool:
        """Record a failed check separately from a valid no-match result."""
        if not isinstance(error, str) or not error.strip():
            raise ValueError("Mapping error must be nonblank text.")
        return self._save_pair_outcome(job_id, clo_id, plo_id, [], None, error)

    def finish_if_complete(self, job_id: UUID | str) -> dict[str, object]:
        """Finish a job only when every pair has a saved result or error, and return its progress."""
        job = self.get(job_id)
        if job is None or not job["pairs_ready"]:
            raise ValueError("Mapping job does not exist or is not ready.")
        if job["status"] not in ("pending", "in_progress"):
            return job
        if job["processed_pairs"] + job["errored_pairs"] != job["total_pairs"]:
            return job

        final_status = "completed"
        if job["errored_pairs"]:
            final_status = "failed"
        try:
            response = self.table.update_item(
                Key=self._key(job_id),
                UpdateExpression="SET #status = :status",
                ExpressionAttributeNames={"#status": "status"},
                ExpressionAttributeValues={":status": final_status},
                # Recheck the progress we read before marking the job finished.
                ConditionExpression=Attr("pairs_ready").eq(True)
                & Attr("input_hash").eq(job["input_hash"])
                & Attr("status").is_in(["pending", "in_progress"])
                & Attr("processed_pairs").eq(job["processed_pairs"])
                & Attr("errored_pairs").eq(job["errored_pairs"]),
                ReturnValues="ALL_NEW",
            )
        except self.table.meta.client.exceptions.ConditionalCheckFailedException:
            # Another attempt may have already finished the job.
            current = self.get(job_id)
            if current is None:
                raise
            return current
        return response["Attributes"]

    def _save_pair_outcome(
        self,
        job_id: UUID | str,
        clo_id: int,
        plo_id: int,
        mapping_scale_ids: list[int],
        explanation: str | None,
        error: str | None,
    ) -> bool:
        """Return True when saved, or False if an active job already has this pair's outcome."""
        job = self.get(job_id)
        if job is None or not job["pairs_ready"]:
            raise ValueError("Mapping job does not exist or is not ready.")
        allowed_ids = {scale["map_scale_id"] for scale in job["mapping_scale_options"]}
        if not set(mapping_scale_ids).issubset(allowed_ids):
            raise ValueError("Mapping scale IDs must belong to the job's original levels.")

        pair_key = {"pk": job["pk"], "sk": f"LO_PAIR#{clo_id}-{plo_id}"}
        # A valid no-match still counts as processed. Failed checks only add to errored_pairs.
        successful = error is None
        # Save the outcome and counts together. A failed write must not leave only one updated.
        # Transaction conditions use strings because Boto3's Attr placeholders are added at the top level.
        try:
            self.table.meta.client.transact_write_items(
                TransactItems=[
                    {
                        "Update": {
                            "TableName": self.table.name,
                            "Key": pair_key,
                            "UpdateExpression": (
                                "SET #processed = :processed, #error = :error, "
                                "mapping_scales = :levels, explanation = :explanation"
                            ),
                            # Only save an existing pair that has neither a result nor an error.
                            "ConditionExpression": "#processed = :pending AND #error = :empty",
                            "ExpressionAttributeNames": {"#processed": "processed", "#error": "error"},
                            "ExpressionAttributeValues": {
                                ":processed": successful,
                                ":error": error,
                                ":levels": mapping_scale_ids,
                                ":explanation": explanation,
                                ":pending": False,
                                ":empty": None,
                            },
                        },
                    },
                    {
                        "Update": {
                            "TableName": self.table.name,
                            "Key": self._key(job_id),
                            # Add to the current counts so overlapping saves cannot lose progress.
                            "UpdateExpression": (
                                "SET #status = :running "
                                "ADD processed_pairs :processed, mapped_pairs :mapped, errored_pairs :errored"
                            ),
                            # Recheck readiness and inputs, and block writes to finished jobs.
                            "ConditionExpression": (
                                "pairs_ready = :ready AND input_hash = :input_hash "
                                "AND #status IN (:pending, :running)"
                            ),
                            "ExpressionAttributeNames": {"#status": "status"},
                            "ExpressionAttributeValues": {
                                ":processed": int(successful),
                                ":mapped": int(bool(mapping_scale_ids)),
                                ":errored": int(not successful),
                                ":ready": True,
                                ":input_hash": job["input_hash"],
                                ":pending": "pending",
                                ":running": "in_progress",
                            },
                        },
                    },
                ],
            )
        except self.table.meta.client.exceptions.TransactionCanceledException as exc:
            # Cancellation reasons follow the update order above: pair first, job second.
            reasons = [reason.get("Code") for reason in exc.response.get("CancellationReasons", [])]
            if reasons == ["ConditionalCheckFailed", "None"]:
                pair = self.table.get_item(Key=pair_key, ConsistentRead=True).get("Item")
                # Keep the first saved outcome, even if a later attempt got a different answer.
                if pair and (pair["processed"] or pair["error"] is not None):
                    return False
            # Missing pairs and other storage failures must still reach the caller.
            raise
        return True
