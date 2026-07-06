import boto3
import logging
import os
from datetime import datetime
import json
import re
from boto3.dynamodb.conditions import Key


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

sm       = boto_session.client("sagemaker")
dynamodb = boto_session.resource("dynamodb")

REGION               = os.environ.get("AWS_REGION", "ca-central-1")

# HuggingFace model config
HF_MODEL_ID          = os.getenv("HF_MODEL_ID")            
HF_TASK              = os.environ.get("HF_TASK", "text-generation")
HF_IMAGE_URI         = os.getenv("HF_IMAGE_URI") 

# Run this once locally (needs boto3 + sagemaker SDK installed locally only):
#
#   from sagemaker.huggingface import get_huggingface_llm_image_uri
#   print(get_huggingface_llm_image_uri("huggingface", version="3.3.6", region="ca-central-1"))          
# Hard-code that string as your HF_IMAGE_URI env var

# Transform job config
INSTANCE_TYPE        = os.environ.get("INSTANCE_TYPE", "ml.g5.2xlarge")
INSTANCE_COUNT       = int(os.environ.get("INSTANCE_COUNT", "1"))
INPUT_S3_URI         = os.getenv("INPUT_S3_URI")            
OUTPUT_S3_URI        = os.getenv("OUTPUT_S3_URI")           
JOB_NAME_PREFIX      = os.environ.get("JOB_NAME_PREFIX", "hf-batch-transform")

# DynamoDB config
DYNAMODB_TABLE       = os.getenv("LO_MAPPING_DYNAMODB_REQUESTS_TABLE")
STATUS_INDEX      = os.getenv("DYNAMODB_STATUS_INDEX") #GSI name for status-createdAt index

# Model environment variables

hub_env = {
    "HF_MODEL_ID":          HF_MODEL_ID,
    "HF_TASK":              "text-generation",
 
    # Generation defaults — override as needed
    "OPTION_MAX_MODEL_LEN":       "2500",
    "OPTION_MAX_SEQ_LEN":         "2500",
    'SM_NUM_GPUS': json.dumps(1),
    'OPTION_ENABLE_REASONING': 'false'
    
}
MODEL_NAME_PREFIX = os.environ.get("MODEL_NAME_PREFIX", "hf-batch-transform-model")

APP_NAME = os.getenv("APP_NAME") 
SAGEMAKER_TAGS = [{"Key": "AppName", "Value": APP_NAME}] if APP_NAME else []


def get_running_transform_job() -> dict | None:
    """Return the first InProgress/Stopping job matching our prefix, or None."""
    active_statuses = {"InProgress", "Stopping"}
    paginator = sm.get_paginator("list_transform_jobs")
    for page in paginator.paginate(NameContains=JOB_NAME_PREFIX):
        for job in page["TransformJobSummaries"]:
            if job["TransformJobStatus"] in active_statuses:
                logger.info(
                    "Active job found: %s [%s]",
                    job["TransformJobName"],
                    job["TransformJobStatus"],
                )
                return job
    return None


def create_model(model_name: str):
    try:
        sm.describe_model(ModelName=model_name)
        logger.info("SageMaker model %s already exists, reusing it.", model_name)
        return
    except sm.exceptions.ClientError as e:
        if e.response.get("Error", {}).get("Code") != "ValidationException":
            raise

    sm.create_model(
        ModelName=model_name,
        PrimaryContainer={
            "Image": HF_IMAGE_URI,
            "Environment": {**hub_env},
        },
        ExecutionRoleArn=SAGEMAKER_ROLE_ARN,
        Tags=SAGEMAKER_TAGS,
    )
    logger.info("Created SageMaker model: %s", model_name)



def start_transform_job(job_name, model_name, input_s3_uri):
    
    sm.create_transform_job(
        TransformJobName=job_name,
        ModelName=model_name,

        BatchStrategy="SingleRecord",
        MaxConcurrentTransforms=1,
        ModelClientConfig={
            "InvocationsTimeoutInSeconds": 120,
        },

        TransformInput={
            "DataSource": {
                "S3DataSource": {
                    "S3DataType": "S3Prefix",
                    "S3Uri": input_s3_uri,
                }
            },
            "ContentType": "application/json",
            "SplitType": "Line",
        },

        TransformOutput={
            "S3OutputPath": OUTPUT_S3_URI,
            "Accept": "application/json",
            "AssembleWith": "Line",
        },

        TransformResources={
            "InstanceType": INSTANCE_TYPE,
            "InstanceCount": INSTANCE_COUNT,
        },
        Tags=SAGEMAKER_TAGS,
    )
    logger.info("Submitted transform job: %s", job_name)

def get_record(table, record_id):
    resp = table.get_item(Key={"request_id": record_id})
    return resp.get("Item")

def get_oldest_pending_before(table, current_created_at):
    """
    Query the GSI for PENDING records with created_at < current record's created_at.
    """
    try:
        resp = table.query(
            IndexName=STATUS_INDEX,
            KeyConditionExpression=(
                Key("status").eq("PENDING") &
                Key("created_at").lt(current_created_at)
            ),
            ScanIndexForward=True,   # ascending → oldest first
            Limit=1,
        )
        items = resp.get("Items", [])
        if items:
            logger.info(
                "Older PENDING record found: %s (createdAt: %s)",
                items[0]["request_id"],
                items[0]["created_at"],
            )
            return items[0]
        return None
    
    except Exception as e:
        raise RuntimeError(f"Error querying for older PENDING records in GSI: {str(e)}") from e
    
    
def mark_in_progress(table, record_id, job_name):
    """Update a record's status to IN_PROGRESS and store the job name."""
    table.update_item(
        Key={"request_id": record_id},
        UpdateExpression=(
            "SET #st = :status, "
            "transform_job_name = :job, "
            "updated_at = :ts"
        ),
        ExpressionAttributeNames={"#st": "status"},
        ExpressionAttributeValues={
            ":status": "IN_PROGRESS",
            ":job":    job_name,
            ":ts":     datetime.now().isoformat(),
        },
    )
    logger.info("Record '%s' → IN_PROGRESS (job: %s)", record_id, job_name)
    
def _is_known(value: str | None) -> bool:
    return bool(value) and (value.strip().lower() != "unknowncourse" and value.strip().lower() != "unknownprogram")
    
def _sanitize_for_job_name(value: str) -> str:
    """Replace characters that are not allowed in SageMaker job names with hyphens."""
    return re.sub(r"[^a-zA-Z0-9\-]", "-", value)

def build_job_name(record) -> str:
    """
    Build a SageMaker Batch Transform job name.

    Format (when course + program are known):
        <PREFIX>-course-<courseNumber>-program-<programNumber>-<timestamp>

    Format (when either is unknown):
        <PREFIX>-<timestamp>
    """
    timestamp      = datetime.now().strftime("%Y%m%d%H%M%S")
    course_number  = record.get("course_id")
    program_number = record.get("program_id")
    
    if course_number is not None:
        course_number = str(course_number)
    if program_number is not None:
        program_number = str(program_number)

    if _is_known(course_number) and _is_known(program_number):
        safe_course  = _sanitize_for_job_name(course_number)
        safe_program = _sanitize_for_job_name(program_number)
        job_name = f"{JOB_NAME_PREFIX}-course-{safe_course}-program-{safe_program}-{timestamp}"
        logger.info(
            "Job name includes course/program: course=%s program=%s",
            course_number, program_number,
        )
    else:
        job_name = f"{JOB_NAME_PREFIX}-{timestamp}"
        logger.info(
            "Course ('%s') or program ('%s') is unknown — omitted from job name.",
            course_number, program_number,
        )

    return job_name

def launch_job_for_record(record) -> str:
    """
    Given a DynamoDB record, create a SageMaker model + transform job
    and return the job name.
    """
    job_name   = build_job_name(record)
    model_name = f"{MODEL_NAME_PREFIX}-{JOB_NAME_PREFIX}"
 
    input_s3_uri = record.get("input_s3_path")
    if not input_s3_uri:
        raise ValueError(f"Record '{record['request_id']}' has no input_s3_path attribute.")
 
    create_model(model_name)
    start_transform_job(job_name, model_name, input_s3_uri)
 
    return job_name


def lambda_handler(event, context) -> dict:
    """
    Expected event payload:
        { "record_id": "<dynamodb-item-id>" }
    """
    logger.info("Lambda invoked. event=%s", event)
 
    record_id = event.get("record_id")
    if not record_id:
        return {"statusCode": 400, "body": {"error": "Missing 'record_id' in event."}}
 
    table = dynamodb.Table(DYNAMODB_TABLE)
 
    running_job = get_running_transform_job()
    if running_job:
        logger.info("SageMaker job already running — no action taken.")
        return {
            "statusCode": 200,
            "body": {
                "message": "Transform job already running. No action taken.",
                "existingJob": running_job["TransformJobName"],
                "status": running_job["TransformJobStatus"],
            },
        }
 
    triggered_record = get_record(table, record_id)
    if not triggered_record:
        return {"statusCode": 404, "body": {"error": f"Record with request_id '{record_id}' not found."}}
 
    if triggered_record.get("status") != "PENDING":
        logger.info(
            "Record with request_id '%s' is not PENDING (status: %s) — no action taken.",
            record_id,
            triggered_record.get("status"),
        )
        return {
            "statusCode": 404,
            "body": {
                "error": f"Record with request_id '{record_id}' is not PENDING (status: {triggered_record.get('status')}). No action taken.",
            },
        }
 
    current_created_at = triggered_record.get("created_at")
    if not current_created_at:
        return {"statusCode": 400, "body": {"error": f"Record with request_id '{record_id}' has no 'created_at' attribute."}}
 
    try:
        older_record = get_oldest_pending_before(table, current_created_at)
    except Exception as e:
        return {"statusCode": 500, "body": {"error": f"Error checking for older PENDING records: {str(e)}"}}
 
    if older_record:
        # Prioritise the older record — start its job instead
        logger.info(
            "Older PENDING record '%s' exists from before '%s'.",
            older_record["request_id"],
            record_id,
        )
        target_record = older_record
    else:
        # No older records — start the triggered record's job
        logger.info("No older PENDING records. Starting job for '%s'.", record_id)
        target_record = triggered_record
 
    job_name = launch_job_for_record(target_record)
    mark_in_progress(table, target_record["request_id"], job_name)
 
    return {
        "statusCode": 200,
        "body": {
            "message": "Batch Transform job submitted.",
            "startedForRecordId": target_record["request_id"],
            "triggeredByRecordId": record_id,
            "jobName": job_name,
            "dynamodbStatus": "IN_PROGRESS",
        },
    }