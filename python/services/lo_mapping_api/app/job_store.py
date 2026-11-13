import hashlib
import json
from datetime import UTC, datetime
from uuid import UUID

import boto3
from boto3.dynamodb.conditions import Attr, Key

from app.config import get_settings
from app.job_models import MappingJobRequest


class MappingJobConflictError(ValueError):
    """The job ID is already being used for different mapping inputs."""


class MappingJobStore:
    """Save and read mapping jobs in DynamoDB. AWS errors reach the caller."""

    def __init__(self, table=None):
        if table is None:
            settings = get_settings()
            table = boto3.resource("dynamodb", region_name=settings.aws_region).Table(
                settings.dynamodb_table_name
            )
        self.table = table

    @staticmethod
    def _key(job_id: UUID | str) -> dict[str, str]:
        return {"pk": f"JOB#{UUID(str(job_id))}", "sk": "METADATA"}

    def create(self, request: MappingJobRequest) -> dict[str, object]:
        """Create the job and all pairs, or resume creation for the same job and inputs."""
        job_id = str(request.job_id)
        # Compare the inputs on retries so an existing job cannot be reused for different work.
        input_json = json.dumps(request.model_dump(mode="json"), sort_keys=True, ensure_ascii=False)
        input_hash = hashlib.sha256(input_json.encode("utf-8")).hexdigest()
        record = {
            **self._key(job_id),
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
        """Return the job metadata, or None if the job does not exist."""
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
