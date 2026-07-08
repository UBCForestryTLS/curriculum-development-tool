import boto3
import json
import logging
import os
import urllib.request
import urllib.error
from datetime import datetime
from boto3.dynamodb.conditions import Key
import re

logger = logging.getLogger()
logger.setLevel(logging.INFO)

ACCESS_KEY = os.getenv("ACCESS_KEY")
SECRET_KEY = os.getenv("SECRET_KEY")
SAGEMAKER_ROLE_ARN = os.getenv("IAM_ROLE_ARN")

boto_session = boto3.Session(
    aws_access_key_id=ACCESS_KEY,
    aws_secret_access_key=SECRET_KEY,
    region_name= "ca-central-1"
)

dynamodb      = boto_session.resource("dynamodb")
lambda_client = boto_session.client("lambda")

DYNAMODB_TABLE        = os.environ["DYNAMODB_TABLE"]
START_JOB_LAMBDA_NAME = os.environ["START_JOB_LAMBDA_NAME"]   # first Lambda's function name
#FASTAPI_ENDPOINT      = os.environ["FASTAPI_ENDPOINT"]   # No longer needed with scheduled job and manual endpoint handling post-processing      
STATUS_INDEX          = os.environ.get("STATUS_INDEX", "status-createdAt-index")
JOB_NAME_PREFIX       = os.environ.get("JOB_NAME_PREFIX", "hf-batch-transform")

def find_in_progress_record(table, job_name: str) -> dict | None:
    """
    Find the IN_PROGRESS record for this job using the status-createdAt GSI
    """
    items = []
    args = {
        "IndexName": STATUS_INDEX,
        "KeyConditionExpression": Key("status").eq("IN_PROGRESS"),
    }
    resp = table.query(**args)
    items.extend(resp.get("Items", []))

    while "LastEvaluatedKey" in resp:
        resp = table.query(**args, ExclusiveStartKey=resp["LastEvaluatedKey"])
        items.extend(resp.get("Items", []))

    if not items:
        logger.warning("No IN_PROGRESS records found in the table.")
        return None

    for r in items:
        if r.get("transform_job_name") == job_name:
            logger.info("Found matching IN_PROGRESS record for job '%s': %s", job_name, r)
            return r
        
    logger.warning("No IN_PROGRESS record matched job name '%s'.", job_name)
    return None


def update_record_status(table, record_id, new_status):
    table.update_item(
        Key={"request_id": record_id},
        UpdateExpression="SET #st = :status, updated_at = :ts",
        ExpressionAttributeNames={"#st": "status"},
        ExpressionAttributeValues={
            ":status": new_status,
            ":ts":     datetime.now().isoformat(),
        },
    )
    logger.info("Record '%s' → %s", record_id, new_status)


def get_oldest_pending(table) -> dict | None:
    """Return the oldest PENDING record via the status-createdAt GSI."""
    resp  = table.query(
        IndexName=STATUS_INDEX,
        KeyConditionExpression=Key("status").eq("PENDING"),
        ScanIndexForward=True,   # ascending → oldest first
        Limit=1,
    )
    items = resp.get("Items", [])
    return items[0] if items else None


## No longer needed with scheduled job and manual endpoint handling post-processing
def get_all_awaiting_completion(table) -> list:
    """Return every record with status AWAITING_COMPLETION or AWAITING_COMPLETION_FAILED."""
    items = []

    for status in ["AWAITING_COMPLETION", "AWAITING_COMPLETION_FAILED"]:
        args = {
            "IndexName": STATUS_INDEX,
            "KeyConditionExpression": Key("status").eq(status),
            "ScanIndexForward": True,
        }
        resp = table.query(**args)
        items.extend(resp.get("Items", []))

        while "LastEvaluatedKey" in resp:
            resp = table.query(**args, ExclusiveStartKey=resp["LastEvaluatedKey"])
            items.extend(resp.get("Items", []))

    return items

def trigger_start_job_lambda(record_id: str):
    """Invoke the first Lambda asynchronously to start the next batch job."""
    response = lambda_client.invoke(
        FunctionName=START_JOB_LAMBDA_NAME,
        InvocationType="Event",                              # async
        Payload=json.dumps({"record_id": record_id}).encode("utf-8"),
    )
    if response["StatusCode"] != 202:
        raise RuntimeError(
            f"Unexpected StatusCode {response['StatusCode']} invoking '{START_JOB_LAMBDA_NAME}'."
        )
    logger.info("Triggered start-job Lambda for record '%s'.", record_id)
 
 
## No longer needed with scheduled job and manual endpoint handling post-processing 
def notify_fastapi(records: list):
    """
    POST all AWAITING_COMPLETION or AWAITING_COMPLETION_FAILED records to FastAPI.
    """
    
    lof_awating_processing_records = []
    for r in records:
        lof_awating_processing_records.append({
                "request_id": r.get("request_id"),
                "course_id": r.get("course_id"),
                "program_id": r.get("program_id"),
                "status": r.get("status"),
                "transform_job_name": r.get("transform_job_name"),
                "created_at": r.get("created_at"),
                "updated_at": r.get("updated_at"),
                "input_s3_path": r.get("input_s3_path"),
                "output_s3_path": r.get("output_s3_path"),
            })
    
    payload = json.dumps({
        "recordsAwaitingProcessing": lof_awating_processing_records
    }).encode("utf-8")

    req = urllib.request.Request(
        FASTAPI_ENDPOINT,
        data=payload,
        headers={"Content-Type": "application/json"},
        method="POST",
    )

    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            logger.info(
                "FastAPI notified — HTTP %s, %d record(s) sent.",
                resp.status,
                len(records),
            )
    except urllib.error.HTTPError as e:
        logger.error("FastAPI returned HTTP %s: %s", e.code, e.read().decode())
        raise
    except urllib.error.URLError as e:
        logger.error("Failed to reach FastAPI: %s", e.reason)
        raise

def parse_job_name(job_name: str) -> tuple[str, str]:
    try:
        parts = job_name.split("-")

        course_idx = parts.index("course")
        program_idx = parts.index("program")

        course = parts[course_idx + 1]
        program = parts[program_idx + 1]

        return course, program
    except Exception as e:
        return "unknown", "unknown"


def lambda_handler(event, context) -> dict:
    """
    Triggered by EventBridge on SageMaker Batch Transform job state change.
    
    Updates the corresponding DynamoDB record's status to AWAITING_COMPLETION or AWAITING_COMPLETION_FAILED.
    Then checks for the next PENDING record and triggers the start-job Lambda if found.
    """
    
    logger.info("EventBridge event: %s", json.dumps(event))

    detail     = event.get("detail", {})
    job_name   = detail.get("TransformJobName")
    job_status = detail.get("TransformJobStatus")   # Completed | Failed | Stopped

    if not job_name or not job_status:
        logger.error("Missing TransformJobName or TransformJobStatus in event detail.")
        return {"statusCode": 400, "body": "Invalid EventBridge event."}

    TERMINAL_STATES = {"Completed", "Failed", "Stopped"}
    if job_status not in TERMINAL_STATES:
        logger.info(
            "Job '%s' is in non-terminal state '%s' - no action taken.",
            job_name, job_status,
        )
        return {
            "statusCode": 200,
            "body": {"message": f"Non-terminal state '{job_status}' - no action taken."},
        }

    logger.info("Job '%s' finished with status: %s", job_name, job_status)

    # course_id, program_id = parse_job_name(job_name)
    # logger.info(
    #     "Parsed from job name — course_id: %s  program_id: %s",
    #     course_id, program_id,
    # )

    table = dynamodb.Table(DYNAMODB_TABLE)

    record = find_in_progress_record(table, job_name)
    if not record:
        logger.error("No IN_PROGRESS record found for job '%s'.", job_name)
        return {
            "statusCode": 404,
            "body": f"No IN_PROGRESS record found for job '{job_name}'.",
        }

    record_id = record["request_id"]

    new_status = "AWAITING_COMPLETION" if job_status == "Completed" else "AWAITING_COMPLETION_FAILED"
    update_record_status(table, record_id, new_status)

    oldest_pending = get_oldest_pending(table)
    if oldest_pending:
        logger.info(
            "Triggering next PENDING record: '%s' (createdAt: %s).",
            oldest_pending["request_id"],
            oldest_pending.get("created_at"),
        )
        trigger_start_job_lambda(oldest_pending["request_id"])
    else:
        logger.info("No PENDING records found — queue is empty.")
    
    
    ## No longer neded with scheduled job and manual endpoint handling post-processing
        
    # awaiting_records = get_all_awaiting_completion(table)

    # # Ensure the current record is in the list even if the GSI lags
    # awaiting_ids = {r["request_id"] for r in awaiting_records}
    # if (new_status == "AWAITING_COMPLETION" or new_status == "AWAITING_COMPLETION_FAILED") and record_id not in awaiting_ids:
    #     logger.info("Adding current record to awaitingCompletion list (GSI lag).")
    #     awaiting_records.append({**record, "status": new_status})

    # if awaiting_records:
    #     logger.info("%d awaitingCompletion record(s) — notifying FastAPI.", len(awaiting_records))
    #     notify_fastapi(awaiting_records)
    # else:
    #     logger.info("No awaitingCompletion records — FastAPI not notified.")
 
 
    return {
        "statusCode": 200,
        "body": {
            "processedJob":        job_name,
            "courseNumber":        record.get("course_id", "unknown"),
            "programNumber":       record.get("program_id", "unknown"),
            "updatedRecordId":     record_id,
            "updatedRecordStatus": new_status,
            "nextJobTriggered":    oldest_pending["request_id"] if oldest_pending else None,
        },
    }
