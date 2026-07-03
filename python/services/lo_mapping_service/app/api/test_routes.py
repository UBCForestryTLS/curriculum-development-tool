"""
Test-only FastAPI endpoints. 
! Never use these in main.py nor deploy them to production !
Used for E2E tests to mock features we don't get due to LocalStack SageMaker limitations.
"""
import datetime
from uuid import uuid4

from app.core.logging_config import logger
from app.services import LOMappingRequestDynamoDBRecord
from app.services.test.mock_sagemaker import MockSageMaker

_store = LOMappingRequestDynamoDBRecord()


def register_test_routes(app) -> None:
    logger.info("Test endpoints registered at /test/*")

    @app.post("/test/put-pending-record/{course_id}/{program_id}")
    def put_pending_record(course_id: int, program_id: int) -> dict:
        # Put a 'freshly submitted' PENDING request record into DynamoDB.
        now = datetime.datetime.now(datetime.timezone.utc)
        request_id = now.strftime("%Y%m%d-%H%M%S-") + str(uuid4())
        _store._get_table().put_item(Item={
            "request_id":     request_id,
            "course_id":      course_id,
            "program_id":     program_id,
            "status":         "PENDING",
            "input_s3_path":  "s3://e2e-fake/input.jsonl",
            "output_s3_path": "s3://e2e-fake/output.jsonl.out",
            "created_at":     now.isoformat(),
            "updated_at":     now.isoformat(),
        })
        return {"status": "ok", "request_id": request_id}

    @app.post("/test/mark-record-in-progress/{course_id}/{program_id}")
    def mark_record_in_progress(course_id: int, program_id: int) -> dict:
        """The start-batch-transform-job Lambda uses SageMaker, so this simulates
        it succeeding (changes PENDING to IN_PROGRESS in DynamoDB)"""
        record = _store.find_in_flight_records_for_pairs(
            [(course_id, program_id)]
        ).get((course_id, program_id))
        if record is None:
            return {"error": "No in-flight record found", "course_id": course_id, "program_id": program_id}

        _store._get_table().update_item(
            Key={"request_id": record["request_id"]},
            UpdateExpression="SET #st = :s, transform_job_name = :j, updated_at = :ts",
            ExpressionAttributeNames={"#st": "status"}, # because 'status' is a reserved word in DynamoDB
            ExpressionAttributeValues={
                ":s":  "IN_PROGRESS",
                ":j":  f"test-job-{record['request_id']}",
                ":ts": datetime.datetime.now(datetime.timezone.utc).isoformat(),
            },
        )
        return {"status": "ok", "request_id": record["request_id"]}

    @app.post("/test/delete-records/{course_id}/{program_id}")
    def delete_records(course_id: int, program_id: int) -> dict:
        """Delete all records for one (course, program) pair. Simulates the
        cleanup that EventBridge does after SageMaker delivers the result."""
        table = _store._get_table()
        deleted = 0
        scan = table.scan()
        while True:
            for item in scan.get("Items", []):
                if int(item.get("course_id")) == course_id and int(item.get("program_id")) == program_id:
                    table.delete_item(Key={"request_id": item["request_id"]})
                    deleted += 1
            if "LastEvaluatedKey" not in scan:
                break
            scan = table.scan(ExclusiveStartKey=scan["LastEvaluatedKey"])
        return {"status": "ok", "deleted": deleted}

    @app.post("/test/get-records/{course_id}/{program_id}")
    def get_records(course_id: int, program_id: int) -> dict:
        table = _store._get_table()
        records = []
        scan = table.scan()
        while True:
            for item in scan.get("Items", []):
                if int(item.get("course_id")) == course_id and int(item.get("program_id")) == program_id:
                    records.append(item)
            # TODO: Could abstract away the do-while LastEvalutedKey pattern, used it thrice
            if "LastEvaluatedKey" not in scan:
                break
            scan = table.scan(ExclusiveStartKey=scan["LastEvaluatedKey"])
        return {"status": "ok", "records": records}

    @app.post("/test/set-awaiting-completion/{course_id}/{program_id}")
    def set_awaiting_completion(course_id: int, program_id: int, body: dict) -> dict:
        """Simulates SageMaker finishing and the EventBridge Lambda triggering.
        Writes mock SageMaker output to S3 and moves the (course, program)'s IN_PROGRESS record
        to AWAITING_COMPLETION."""
        record = _store.find_in_flight_records_for_pairs(
            [(course_id, program_id)]
        ).get((course_id, program_id))
        
        output_s3_path = MockSageMaker().write_output(
            course_id, program_id, body.get("suggestions", [])
        )

        _store._get_table().update_item(
            Key={"request_id": record["request_id"]},
            UpdateExpression="SET #st = :s, output_s3_path = :o, updated_at = :ts",
            ExpressionAttributeNames={"#st": "status"},  # 'status' is a reserved word in DynamoDB
            ExpressionAttributeValues={
                ":s":  "AWAITING_COMPLETION",
                ":o":  output_s3_path,
                ":ts": datetime.datetime.now(datetime.timezone.utc).isoformat(),
            },
        )
        return {"status": "ok", "request_id": record["request_id"], "output_s3_path": output_s3_path}

    @app.post("/test/clear-dynamodb-aisuggestions")
    def clear_dynamodb() -> dict:
        """Empty the DynamoDB AI Suggestions requests table 
        (delete all items, but keep the table structure)"""
        table = _store._get_table()
        deleted = 0
        scan = table.scan(ProjectionExpression="request_id")
        while True: 
            # Couldn't find a cleaner way to delete all items while still keeping table structure
            # TODO: If a cleaner approach exists, replace
            items = scan.get("Items", [])
            with table.batch_writer() as batch:
                for item in items:
                    batch.delete_item(Key={"request_id": item["request_id"]})
            deleted += len(items)
            if "LastEvaluatedKey" not in scan:
                break
            scan = table.scan(
                ProjectionExpression="request_id",
                ExclusiveStartKey=scan["LastEvaluatedKey"],
            )
        return {"status": "ok", "deleted": deleted}
