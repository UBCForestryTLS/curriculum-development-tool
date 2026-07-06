import os
from datetime import datetime
from uuid import uuid4

import boto3
from boto3.dynamodb.conditions import Key

from app.core.config import Settings
from app.core.logging_config import logger


class LOMappingRequestDynamoDBRecord:
    """Persists LO mapping request records in DynamoDB."""

    def __init__(self, settings: Settings | None = None):
        self.settings = settings or Settings()
        self.table_name = self.settings.LO_MAPPING_DYNAMODB_REQUESTS_TABLE
        self.aws_region = self.settings.AWS_REGION
        self.aws_access_key = self.settings.ACCESS_KEY
        self.aws_secret_key = self.settings.SECRET_KEY
        self.status_index = self.settings.DYNAMODB_STATUS_INDEX
        self.output_s3_uri = self.settings.OUTPUT_S3_URI

    def ensure_table_exists(self):
        if not self.table_name:
            raise ValueError("LO_MAPPING_DYNAMODB_REQUESTS_TABLE is not set")

        boto_session = self._create_boto_session()
        dynamodb = boto_session.resource("dynamodb", region_name=self.aws_region)
        client  = boto_session.client("dynamodb", region_name=self.aws_region)

        try:
            client.describe_table(TableName=self.table_name)
            logger.info("DynamoDB table %s already exists", self.table_name)
            return
        except client.exceptions.ResourceNotFoundException:
            logger.info("DynamoDB table %s not found. Creating it now.", self.table_name)
            table = dynamodb.create_table(
                TableName=self.table_name,
                KeySchema=[
                    {"AttributeName": "request_id", "KeyType": "HASH"},
                ],
                AttributeDefinitions=[
                    {"AttributeName": "request_id", "AttributeType": "S"},
                    {"AttributeName": "status", "AttributeType": "S"},
                    {"AttributeName": "created_at", "AttributeType": "S"},
                ],
                BillingMode="PAY_PER_REQUEST",
                GlobalSecondaryIndexes=[
                    {
                        "IndexName": "status-created_at-index",
                        "KeySchema": [
                            {"AttributeName": "status", "KeyType": "HASH"},
                            {"AttributeName": "created_at", "KeyType": "RANGE"},
                        ],
                        "Projection": {"ProjectionType": "ALL"},
                    },
                ],
            )
            table.wait_until_exists()
            logger.info("DynamoDB table %s is ready", self.table_name)

            try:
                client.tag_resource(
                    ResourceArn=table.table_arn,
                    Tags=[{"Key": "AppName", "Value": self.settings.APP_NAME}],
                )
                logger.info("Tagged DynamoDB table %s with AppName=%s", self.table_name, self.settings.APP_NAME)
            except Exception:
                logger.warning("Could not tag DynamoDB table %s", self.table_name, exc_info=True)

    def create_request(self, course_id, program_id, input_s3_path, status: str = "PENDING",):
        if not self.table_name:
            raise ValueError("LO_MAPPING_DYNAMODB_REQUESTS_TABLE is not set")
        if not input_s3_path:
            raise ValueError("input_s3_path is required")
        if not self.output_s3_uri:
            raise ValueError("OUTPUT_S3_URI is not set")

        # SageMaker writes outputs as <OUTPUT_S3_URI>/<input_filename>.out, so
        # mirror that exactly rather than guessing from the input path.
        output_prefix = self.output_s3_uri.rstrip("/")
        input_filename = input_s3_path.rsplit("/", 1)[-1]
        output_s3_path = f"{output_prefix}/{input_filename}.out"

        item = {
            "request_id": datetime.now().strftime('%Y%m%d-%H%M%S-') + str(uuid4()), # Generate based on datetime to ensure uniqueness
            "course_id": course_id,
            "program_id": program_id,
            "status": status,
            "input_s3_path": input_s3_path,
            "output_s3_path": output_s3_path,
            "created_at": datetime.now().isoformat(),
        }

        try:
            logger.info(
                "create_request: writing to table=%s endpoint_url=%s region=%s item_keys=%s",
                self.table_name,
                os.environ.get("AWS_ENDPOINT_URL", "<unset>"),
                self.aws_region,
                list(item.keys()),
            )
            table = self._create_boto_session().resource(
                "dynamodb", region_name=self.aws_region
            ).Table(self.table_name)
            table.put_item(Item=item)
            logger.info(
                "Created LO mapping request record in DynamoDB request_id=%s course_id=%s program_id=%s",
                item["request_id"],
                course_id,
                program_id,
            )
            return item
        except Exception as e:
            logger.exception("create_request failed for table=%s", self.table_name)
            raise RuntimeError(f"Failed to create LO mapping request record in DynamoDB: {str(e)}") from e

    def get_records_by_status(self, status: str) -> list[dict]:
        """Return all records for a given status using the status-created_at GSI"""
        
        table = self._get_table()
        items = []
        args = {
            "IndexName": self.status_index,
            "KeyConditionExpression": Key("status").eq(status),
        }

        response = table.query(**args)
        items.extend(response.get("Items", []))

        while "LastEvaluatedKey" in response:
            response = table.query(**args, ExclusiveStartKey=response["LastEvaluatedKey"])
            items.extend(response.get("Items", []))

        return items

    def find_latest_awaiting_record_by_ids(self, course_id : int, program_id : int) -> dict | None:
        """Find the latest record for the given course/program awaiting post-processing"""

        latest_record = None
        table = self._get_table()

        # DynamoDB returns numeric attributes as Decimal but inputs may be int or str.
        # Normalize both sides to str for comparison.
        target_course_id  = str(course_id)
        target_program_id = str(program_id)

        for status in ("AWAITING_COMPLETION", "AWAITING_COMPLETION_FAILED"):
            response = table.query(
                IndexName=self.status_index,
                KeyConditionExpression=Key("status").eq(status),
            )

            for item in response.get("Items", []):
                if str(item.get("course_id")) != target_course_id or str(item.get("program_id")) != target_program_id:
                    continue
                if latest_record is None or item.get("created_at", "") > latest_record.get("created_at", ""):
                    latest_record = item

            while "LastEvaluatedKey" in response:
                response = table.query(
                    IndexName=self.status_index,
                    KeyConditionExpression=Key("status").eq(status),
                    ExclusiveStartKey=response["LastEvaluatedKey"],
                )
                for item in response.get("Items", []):
                    if str(item.get("course_id")) != target_course_id or str(item.get("program_id")) != target_program_id:
                        continue
                    if latest_record is None or item.get("created_at", "") > latest_record.get("created_at", ""):
                        latest_record = item

        return latest_record

    IN_FLIGHT_STATUSES = (
        "PENDING",
        "IN_PROGRESS",
        "AWAITING_COMPLETION",
        "AWAITING_COMPLETION_FAILED",
    )

    def find_in_flight_records_for_pairs(self, pairs: list[tuple[int, int]]) -> dict:
        """
        Given a list of (course_id, program_id) tuples, return a dict mapping each
        tuple to the latest in-flight DynamoDB record (or None if none exists).

        "In-flight" means the record exists in any status that hasn't been delivered
        to Laravel and deleted yet (PENDING, IN_PROGRESS, AWAITING_COMPLETION,
        AWAITING_COMPLETION_FAILED).

        Single GSI scan per status; in-memory filter for the requested pairs.
        
        Note: both course and program IDs must be of type int
        """
        if not pairs:
            return None
        
        result = {pair: None for pair in pairs}

        pair_set = set(pairs)
        table = self._get_table()

        for status in self.IN_FLIGHT_STATUSES:
            args = {
                "IndexName": self.status_index,
                "KeyConditionExpression": Key("status").eq(status),
            }
            response = table.query(**args)
            self._collect_matching(response.get("Items", []), pair_set, result)

            while "LastEvaluatedKey" in response: # Paginate if there are more results to be read
                response = table.query(**args, ExclusiveStartKey=response["LastEvaluatedKey"])
                self._collect_matching(response.get("Items", []), pair_set, result)

        return result

    @staticmethod
    def _collect_matching(items: list[dict], pair_set: set, result: dict) -> None:
        for item in items:
            pair = (item.get("course_id"), item.get("program_id"))
            if pair not in pair_set:
                continue
            # If multiple in-flight records exist for the same pair, return the latest one
            # Note: This shouldn't happen due to the guards we have set up (in both the frontend and service), but just in case
            current = result.get(pair)
            if current is None or item.get("created_at", "") > current.get("created_at", ""):
                result[pair] = item

    def delete_request(self, request_id: str) -> None:
        """Delete a record by its request_id"""
        self._get_table().delete_item(Key={"request_id": request_id})
        logger.info("Deleted LO mapping request record '%s'.", request_id)

    def _create_boto_session(self) -> boto3.Session:
        if not self.aws_access_key or not self.aws_secret_key or not self.aws_region:
            raise ValueError("AWS credentials or region are not set in environment variables")

        return boto3.Session(
            aws_access_key_id=self.aws_access_key,
            aws_secret_access_key=self.aws_secret_key,
            region_name=self.aws_region,
        )

    def _get_table(self):
        if not self.table_name:
            raise ValueError("LO_MAPPING_DYNAMODB_REQUESTS_TABLE is not set")
        return self._create_boto_session().resource("dynamodb").Table(self.table_name)
