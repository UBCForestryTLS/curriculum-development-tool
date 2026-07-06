"""Deploys the LO Mapping Service's AWS Lambda functions and EventBridge rule to AWS.
If a Lambda already exists, its code and configuration are updated
in place instead of failing.

Run this script from the service's virtual env.
The set up for vitual env is described in docs/setup.md

Prerequisites:
    - AWS CLI installed and configured with credentials via `aws configure`
    - IAM execution role exists with the required permissions policies
    - lo_mapping_service/.env at the service root is filled in based on the .env.example template
"""

import json
import sys
import zipfile
from pathlib import Path
from urllib.parse import urlparse

import boto3
from botocore.exceptions import ClientError
from pydantic_settings import BaseSettings, SettingsConfigDict

# Can remove SERVICE_ROOT if we limit where we call this script from
SERVICE_ROOT = Path(__file__).resolve().parent.parent
DEPLOY_DIR = SERVICE_ROOT / "deploy"
HANDLERS_DIR = SERVICE_ROOT / "app" / "lambda_handlers"

# Configure here just in case we want to add alternative versions or change names
START_JOB_FN = "start-batch-transform-job"
PROCESS_RESULTS_FN = "process-batch-transform-results"
START_JOB_MODULE = "lambda_handler_start_batch_tranform_job"
PROCESS_RESULTS_MODULE = "lambda_handler_process_batch_transform_inference_results"
EVENTBRIDGE_RULE = "sagemaker-transform-job-state-change"


class DeploySettings(BaseSettings):
    AWS_REGION: str
    LO_MAPPING_DYNAMODB_REQUESTS_TABLE: str
    HF_MODEL_ID: str
    HF_IMAGE_URI: str
    OUTPUT_S3_URI: str
    IAM_ROLE_ARN: str
    LAMBDA_ROLE_ARN: str

    ACCESS_KEY: str = ""
    SECRET_KEY: str = ""
    DYNAMODB_STATUS_INDEX: str = "status-created_at-index"
    APP_NAME: str = "curriculum-development-tool"

    model_config = SettingsConfigDict(
        env_file=str(SERVICE_ROOT / ".env"),
        env_file_encoding="utf-8",
        case_sensitive=True,
        extra="ignore",
    )


def load_settings() -> DeploySettings:
    print("Loading .env...")
    try:
        settings = DeploySettings()
    except Exception as e:
        sys.exit(f"ERROR loading .env: {e}")

    for key in (
        "AWS_REGION",
        "LO_MAPPING_DYNAMODB_REQUESTS_TABLE",
        "DYNAMODB_STATUS_INDEX",
        "HF_MODEL_ID",
        "HF_IMAGE_URI",
        "OUTPUT_S3_URI",
        "IAM_ROLE_ARN",
        "LAMBDA_ROLE_ARN",
    ):
        print(f"  {key} = {getattr(settings, key)}")
    print(f"  APP_NAME = {settings.APP_NAME}")
    return settings


def kv_to_lambda_tags(kv_tags: list[dict]) -> dict:
    """Lambda wants tags as {key: value}; the other services use [{Key, Value}]."""
    return {tag["Key"]: tag["Value"] for tag in kv_tags}


def zip_handler(module_name: str, dest: Path) -> Path:
    # Currently, the only external library used by the lambda handlers 
    # is boto3, which is included in the AWS lambda runtime.
    # If we add external dependencies in the future that aren't included,
    # we'd need this script to install those dependencies into a directory
    # which would be zipped along with the handler file, for each handler.
    
    src = HANDLERS_DIR / f"{module_name}.py"
    if not src.exists():
        sys.exit(f"ERROR: handler not found at {src}")
    with zipfile.ZipFile(dest, "w", zipfile.ZIP_DEFLATED) as zf:
        zf.write(src, arcname=src.name)
    print(f"  zipped {src.name} -> {dest}")
    return dest


def zip_handlers() -> list[Path]:
    print("Zipping handlers...")
    return [
        zip_handler(START_JOB_MODULE, DEPLOY_DIR / "start-batch-transform-job.zip"),
        zip_handler(PROCESS_RESULTS_MODULE, DEPLOY_DIR / "process-batch-transform-results.zip"),
    ]


def deploy_lambda(
    lambda_client,
    function_name: str,
    handler_module: str,
    zip_path: Path,
    role_arn: str,
    variables: dict[str, str],
    kv_tags: list[dict],
) -> None:
    handler = f"{handler_module}.lambda_handler"
    zip_bytes = zip_path.read_bytes()
    try:
        lambda_client.create_function(
            FunctionName=function_name,
            Runtime="python3.12",
            Role=role_arn,
            Handler=handler,
            Code={"ZipFile": zip_bytes},
            Timeout=300,
            Environment={"Variables": variables},
        )
        print(f"  created {function_name}")
    except lambda_client.exceptions.ResourceConflictException:
        lambda_client.update_function_code(FunctionName=function_name, ZipFile=zip_bytes)
        # update_function_configuration is rejected while a code update is in progress.
        lambda_client.get_waiter("function_updated").wait(FunctionName=function_name)
        lambda_client.update_function_configuration(
            FunctionName=function_name,
            Role=role_arn,
            Handler=handler,
            Timeout=300,
            Environment={"Variables": variables},
        )
        print(f"  updated {function_name}")

    arn = lambda_client.get_function(FunctionName=function_name)["Configuration"]["FunctionArn"]
    lambda_tags = kv_to_lambda_tags(kv_tags)
    lambda_client.tag_resource(Resource=arn, Tags=lambda_tags)
    print(f"  tagged {function_name} ({lambda_tags})")


def deploy_start_job(lambda_client, settings: DeploySettings, zip_path: Path, kv_tags: list[dict]) -> None:
    print(f"Deploying {START_JOB_FN}...")
    variables = {
        "ACCESS_KEY": settings.ACCESS_KEY,
        "SECRET_KEY": settings.SECRET_KEY,
        "IAM_ROLE_ARN": settings.IAM_ROLE_ARN,
        "HF_MODEL_ID": settings.HF_MODEL_ID,
        "HF_IMAGE_URI": settings.HF_IMAGE_URI,
        "HF_TASK": "text-generation",
        "INSTANCE_TYPE": "ml.g5.2xlarge",
        "INSTANCE_COUNT": "1",
        "OUTPUT_S3_URI": settings.OUTPUT_S3_URI,
        "JOB_NAME_PREFIX": "hf-batch-transform",
        "MODEL_NAME_PREFIX": "hf-batch-transform-model",
        "LO_MAPPING_DYNAMODB_REQUESTS_TABLE": settings.LO_MAPPING_DYNAMODB_REQUESTS_TABLE,
        "DYNAMODB_STATUS_INDEX": settings.DYNAMODB_STATUS_INDEX,
        "APP_NAME": settings.APP_NAME,
    }
    deploy_lambda(lambda_client, START_JOB_FN, START_JOB_MODULE, zip_path,
                  settings.LAMBDA_ROLE_ARN, variables, kv_tags)


def deploy_process_results(lambda_client, settings: DeploySettings, zip_path: Path, kv_tags: list[dict]) -> None:
    print(f"Deploying {PROCESS_RESULTS_FN}...")
    variables = {
        "ACCESS_KEY": settings.ACCESS_KEY,
        "SECRET_KEY": settings.SECRET_KEY,
        "DYNAMODB_TABLE": settings.LO_MAPPING_DYNAMODB_REQUESTS_TABLE,
        "START_JOB_LAMBDA_NAME": START_JOB_FN,
        "STATUS_INDEX": settings.DYNAMODB_STATUS_INDEX,
        "JOB_NAME_PREFIX": "hf-batch-transform",
    }
    deploy_lambda(lambda_client, PROCESS_RESULTS_FN, PROCESS_RESULTS_MODULE, zip_path,
                  settings.LAMBDA_ROLE_ARN, variables, kv_tags)


def log_lambdas(lambda_client) -> None:
    for fn in [START_JOB_FN, PROCESS_RESULTS_FN]:
        cfg = lambda_client.get_function(FunctionName=fn)["Configuration"]
        print(f"  {fn}:")
        print(f"    LastModified = {cfg.get('LastModified')}")
        print(f"    Runtime      = {cfg.get('Runtime')}")
        print(f"    Handler      = {cfg.get('Handler')}")
        print(f"    Role         = {cfg.get('Role')}")
    print("\n")


def setup_eventbridge(events_client, lambda_client, sts_client, region: str | None, kv_tags: list[dict]) -> None:
    print(f"Setting up EventBridge rule '{EVENTBRIDGE_RULE}'...")
    pattern = json.dumps({
        "source": ["aws.sagemaker"],
        "detail-type": ["SageMaker Transform Job State Change"],
    })
    rule = events_client.put_rule(Name=EVENTBRIDGE_RULE, EventPattern=pattern)
    print(f"  rule '{EVENTBRIDGE_RULE}' put")

    events_client.tag_resource(ResourceARN=rule["RuleArn"], Tags=kv_tags)
    print(f"  tagged rule '{EVENTBRIDGE_RULE}'")

    lambda_arn = lambda_client.get_function(
        FunctionName=PROCESS_RESULTS_FN
    )["Configuration"]["FunctionArn"]
    events_client.put_targets(
        Rule=EVENTBRIDGE_RULE,
        Targets=[{"Id": "1", "Arn": lambda_arn}],
    )
    print(f"  target -> {lambda_arn}")

    account_id = sts_client.get_caller_identity()["Account"]
    source_arn = f"arn:aws:events:{region}:{account_id}:rule/{EVENTBRIDGE_RULE}"
    # We have to add permission for EventBridge to invoke the Lambda separately after put_targets,
    # eventbridge-invoke doesn't come under the IAM execution role permissions
    # The user running this script must have the permission for lambda:AddPermission
    try:
        lambda_client.add_permission(
            FunctionName=PROCESS_RESULTS_FN,
            StatementId="eventbridge-invoke",
            Action="lambda:InvokeFunction",
            Principal="events.amazonaws.com",
            SourceArn=source_arn,
        )
        print(f"  invoke permission added (source: {source_arn})")
    except lambda_client.exceptions.ResourceConflictException:
        print(f"  invoke permission already present (source: {source_arn})")


def ensure_dynamodb_table(dynamodb_client, table_name: str, kv_tags: list[dict]) -> None:
    # NOTE: Keep schema below in sync with schema in LOMappingRequestDynamoDBRecord.ensure_table_exists()
    print(f"Ensuring DynamoDB table '{table_name}'...")
    try:
        arn = dynamodb_client.describe_table(TableName=table_name)["Table"]["TableArn"]
    except dynamodb_client.exceptions.ResourceNotFoundException:
        print("  table not found; creating it")
        dynamodb_client.create_table(
            TableName=table_name,
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
            Tags=kv_tags,
        )
        dynamodb_client.get_waiter("table_exists").wait(TableName=table_name)
        print(f"  created and tagged DynamoDB table '{table_name}'")
        return

    dynamodb_client.tag_resource(ResourceArn=arn, Tags=kv_tags)
    print(f"  tagged DynamoDB table '{table_name}'")


def ensure_s3_bucket(s3_client, output_s3_uri: str, region: str | None, kv_tags: list[dict]) -> None:
    bucket = urlparse(output_s3_uri).netloc
    if not bucket:
        print(f"  could not parse bucket from OUTPUT_S3_URI '{output_s3_uri}'; skipping S3 step")
        return
    print(f"Ensuring S3 bucket '{bucket}'...")

    try:
        s3_client.head_bucket(Bucket=bucket)
        print("  bucket already exists")
    except ClientError as e:
        code = e.response.get("Error", {}).get("Code")
        if code == "404":
            print("  bucket not found; creating it")
            create_kwargs = {"Bucket": bucket, "CreateBucketConfiguration": {"LocationConstraint": region}}
            s3_client.create_bucket(**create_kwargs)
            s3_client.get_waiter("bucket_exists").wait(Bucket=bucket)
            print(f"  created S3 bucket '{bucket}'")
        else:
            raise

    # Tag and merge with any existing tags because put_bucket_tagging replaces all tags
    try:
        existing = s3_client.get_bucket_tagging(Bucket=bucket).get("TagSet", [])
    except ClientError as e:
        if e.response.get("Error", {}).get("Code") == "NoSuchTagSet":
            existing = []
        else:
            raise
    our_keys = {tag["Key"] for tag in kv_tags}
    merged = [t for t in existing if t["Key"] not in our_keys] + kv_tags
    s3_client.put_bucket_tagging(Bucket=bucket, Tagging={"TagSet": merged})
    print(f"  tagged S3 bucket '{bucket}'")


def main() -> None:
    print(f"Service root: {SERVICE_ROOT}")
    settings = load_settings()

    session = boto3.Session(region_name=settings.AWS_REGION)
    lambda_client = session.client("lambda")
    events_client = session.client("events")
    sts_client = session.client("sts")
    dynamodb_client = session.client("dynamodb")
    s3_client = session.client("s3")

    kv_tags = [{"Key": "AppName", "Value": settings.APP_NAME}]

    start_zip, process_zip = zip_handlers()
    deploy_start_job(lambda_client, settings, start_zip, kv_tags)
    deploy_process_results(lambda_client, settings, process_zip, kv_tags)
    log_lambdas(lambda_client)
    setup_eventbridge(events_client, lambda_client, sts_client, settings.AWS_REGION, kv_tags)
    ensure_dynamodb_table(dynamodb_client, settings.LO_MAPPING_DYNAMODB_REQUESTS_TABLE, kv_tags)
    ensure_s3_bucket(s3_client, settings.OUTPUT_S3_URI, settings.AWS_REGION, kv_tags)

    print("\nDone.")


if __name__ == "__main__":
    main()