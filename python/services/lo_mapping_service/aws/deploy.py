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
import traceback
import zipfile
from pathlib import Path
from urllib.parse import urlparse

import boto3
from botocore.exceptions import ClientError
from pydantic_settings import BaseSettings, SettingsConfigDict

AWS_DIR = Path(__file__).resolve().parent
DIST_DIR = AWS_DIR / "dist"
HANDLERS_DIR = AWS_DIR / "lambda_handlers"

# Configure here just in case we want to add alternative versions or change names
APP_ARN_PATH = "/curriculum-tool/"

LAMBDA_ROLE_NAME = "LambdaExecutionRole"
SAGEMAKER_ROLE_NAME = "SageMakerExecutionRole"

START_LAMBDA_FUNCTION_NAME = "start-batch-transform-job"
PROCESS_LAMBDA_FUNCTION_NAME = "process-batch-transform-results"

START_JOB_MODULE = "lambda_handler_start_batch_tranform_job"
PROCESS_RESULTS_MODULE = "lambda_handler_process_batch_transform_inference_results"

EVENTBRIDGE_RULE = "sagemaker-transform-job-state-change"


class DeploySettings(BaseSettings):
    APP_NAME: str = "curriculum-development-tool"
    AWS_REGION: str

    LO_MAPPING_DYNAMODB_REQUESTS_TABLE: str
    DYNAMODB_STATUS_INDEX: str = "status-created_at-index"

    HF_MODEL_ID: str
    HF_IMAGE_URI: str
    HF_TASK: str = "text-generation"
    INSTANCE_TYPE: str = "ml.g5.2xlarge"
    INSTANCE_COUNT: int = 1
    JOB_NAME_PREFIX: str = "hf-batch-transform"
    MODEL_NAME_PREFIX: str = "hf-batch-transform-model"

    S3_BUCKET_BASE: str = "curriculum-tool-bucket"

    model_config = SettingsConfigDict(
        env_file=str(AWS_DIR / ".env.aws"),
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

    for key, value in settings.model_dump().items():
        print(f'    {key} = {value}')

    print()
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

    if not dest.parent.is_dir():
        dest.parent.mkdir()
    
    with zipfile.ZipFile(dest, "w", zipfile.ZIP_DEFLATED) as zf:
        zf.write(src, arcname=src.name)
    print(f"  zipped {src.name} -> {dest}")
    return dest


def zip_handlers() -> list[Path]:
    print("Zipping handlers...")
    return [
        zip_handler(START_JOB_MODULE, DIST_DIR / "start-batch-transform-job.zip"),
        zip_handler(PROCESS_RESULTS_MODULE, DIST_DIR / "process-batch-transform-results.zip"),
    ]


def deploy_lambda(
    lambda_client,
    function_name: str,
    handler_module: str,
    zip_path: Path,
    role_arn: str,
    variables: dict[str, str],
    kv_tags: list[dict],
) -> str:
    handler = f"{handler_module}.lambda_handler"
    zip_bytes = zip_path.read_bytes()

    print(f"Deploying lambda \"{function_name}\" with role \"{role_arn}\"...")

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

    lambda_arn = lambda_client.get_function(FunctionName=function_name)["Configuration"]["FunctionArn"]
    lambda_tags = kv_to_lambda_tags(kv_tags)

    lambda_client.tag_resource(Resource=lambda_arn, Tags=lambda_tags)
    print(f"  tagged {function_name} ({lambda_tags})")

    return lambda_arn


def get_or_create_start_lambda(
    lambda_client,
    settings: DeploySettings,
    zip_path: Path,
    lambda_role_arn: str,
    sagemaker_role_arn: str,
    s3_bucket_name: str,
    kv_tags: list[dict]
) -> str:
    print(f"Deploying {START_LAMBDA_FUNCTION_NAME}...")
    variables = {
        #"ACCESS_KEY": settings.ACCESS_KEY,
        #"SECRET_KEY": settings.SECRET_KEY,
        "SAGEMAKER_ROLE_ARN": sagemaker_role_arn,

        "APP_NAME": settings.APP_NAME,
        #"AWS_REGION": settings.AWS_REGION,
        "HF_MODEL_ID": settings.HF_MODEL_ID,
        "HF_IMAGE_URI": settings.HF_IMAGE_URI,
        "HF_TASK": settings.HF_TASK,
        "INSTANCE_TYPE": settings.INSTANCE_TYPE,
        "INSTANCE_COUNT": str(settings.INSTANCE_COUNT),
        "OUTPUT_S3_URI": f"s3://{s3_bucket_name}/output/",
        "JOB_NAME_PREFIX": settings.JOB_NAME_PREFIX,
        "MODEL_NAME_PREFIX": settings.MODEL_NAME_PREFIX,
        "LO_MAPPING_DYNAMODB_REQUESTS_TABLE": settings.LO_MAPPING_DYNAMODB_REQUESTS_TABLE,
        "DYNAMODB_STATUS_INDEX": settings.DYNAMODB_STATUS_INDEX,
    }
    return deploy_lambda(
        lambda_client,
        START_LAMBDA_FUNCTION_NAME,
        START_JOB_MODULE,
        zip_path,
        lambda_role_arn, 
        variables, 
        kv_tags
    )


def get_or_create_process_lambda(
    lambda_client,
    settings: DeploySettings,
    zip_path: Path,
    lambda_role_arn: str,
    kv_tags: list[dict]
) -> str:
    print(f"Deploying {PROCESS_LAMBDA_FUNCTION_NAME}...")
    variables = {
        #"ACCESS_KEY": settings.ACCESS_KEY,
        #"SECRET_KEY": settings.SECRET_KEY,
        #"AWS_REGION": settings.AWS_REGION,
        "DYNAMODB_TABLE": settings.LO_MAPPING_DYNAMODB_REQUESTS_TABLE,
        "START_JOB_LAMBDA_NAME": START_LAMBDA_FUNCTION_NAME,
        "STATUS_INDEX": settings.DYNAMODB_STATUS_INDEX,
        "JOB_NAME_PREFIX": settings.JOB_NAME_PREFIX,
    }
    return deploy_lambda(
        lambda_client,
        PROCESS_LAMBDA_FUNCTION_NAME,
        PROCESS_RESULTS_MODULE,
        zip_path,
        lambda_role_arn, 
        variables,
        kv_tags
    )


def log_lambdas(
    lambda_client,
    lambda_arns: list[str]
) -> None:
    for arn in lambda_arns:
        cfg = lambda_client.get_function(FunctionName=arn)["Configuration"]
        print(f"  {cfg.get('FunctionName', '(Unknown Name)')}:")
        print(f"    LastModified = {cfg.get('LastModified')}")
        print(f"    Runtime      = {cfg.get('Runtime')}")
        print(f"    Handler      = {cfg.get('Handler')}")
        print(f"    Role         = {cfg.get('Role')}")
    print("\n")


def setup_eventbridge(
        settings: DeploySettings,
        events_client, 
        lambda_client,
        sts_client,
        kv_tags: list[dict]
) -> None:
    print(f"Setting up EventBridge rule '{EVENTBRIDGE_RULE}'...")
    
    pattern = json.dumps({
        "source": ["aws.sagemaker"],
        "detail-type": ["SageMaker Transform Job State Change"],
        "detail": {
            "TransformJobName": [{
                "prefix": settings.JOB_NAME_PREFIX
            }]
        }
    })
    
    rule = events_client.put_rule(Name=EVENTBRIDGE_RULE, EventPattern=pattern)
    print(f"  rule '{EVENTBRIDGE_RULE}' put")

    events_client.tag_resource(ResourceARN=rule["RuleArn"], Tags=kv_tags)
    print(f"  tagged rule '{EVENTBRIDGE_RULE}'")

    lambda_arn = lambda_client.get_function(
        FunctionName=PROCESS_LAMBDA_FUNCTION_NAME
    )["Configuration"]["FunctionArn"]

    events_client.put_targets(
        Rule=EVENTBRIDGE_RULE,
        Targets=[{"Id": "1", "Arn": lambda_arn}],
    )
    print(f"  target -> {lambda_arn}")

    account_id = sts_client.get_caller_identity()["Account"]
    source_arn = f"arn:aws:events:{settings.AWS_REGION}:{account_id}:rule/{EVENTBRIDGE_RULE}"
    # We have to add permission for EventBridge to invoke the Lambda separately after put_targets,
    # eventbridge-invoke doesn't come under the IAM execution role permissions
    # The user running this script must have the permission for lambda:AddPermission
    try:
        lambda_client.add_permission(
            FunctionName=PROCESS_LAMBDA_FUNCTION_NAME,
            StatementId="eventbridge-invoke",
            Action="lambda:InvokeFunction",
            Principal="events.amazonaws.com",
            SourceArn=source_arn,
        )
        print(f"  invoke permission added (source: {source_arn})")
    except lambda_client.exceptions.ResourceConflictException:
        print(f"  invoke permission already present (source: {source_arn})")


def get_or_create_dynamodb_table(dynamodb_client, table_name: str, kv_tags: list[dict]) -> str | None:
    # NOTE: Keep schema below in sync with schema in LOMappingRequestDynamoDBRecord.ensure_table_exists()
    print(f"Ensuring DynamoDB table '{table_name}' exists...")
    try:
        arn = dynamodb_client.describe_table(TableName=table_name)["Table"]["TableArn"]
    except dynamodb_client.exceptions.ResourceNotFoundException:
        print("  table not found; creating it")
        response = dynamodb_client.create_table(
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

        # return the table ARN
        return response.get('TableDescription', {}).get('TableArn')

    dynamodb_client.tag_resource(ResourceArn=arn, Tags=kv_tags)
    print(f"  tagged DynamoDB table '{table_name}'")

    return arn

def generate_s3_bucket_name(
    base_name: str,
    account_number: str
):
    """
    Generate a pseudo-random S3 bucket name. Uses the provided AWS account number to
    seed the random number generator, ensuring the same random suffix is generated
    each time the function is called for a given account.
    """

    import string
    import random

    random.seed(account_number)

    # bucket names can only contain lowercase letters, numbers, periods and hyphens
    choices = string.ascii_lowercase + string.digits

    bucket_name = base_name.rstrip('-') + '-' + ''.join([random.choice(choices) for i in range(12)])

    if len(bucket_name) > 63:
        raise Exception(f"Generated S3 bucket name \"{bucket_name}\" is too long. Please choose a shorter base name.")
    
    return bucket_name

def get_or_create_s3_bucket(
    s3_client,
    bucket_name: str,
    #output_s3_uri: str,
    region: str | None, 
    kv_tags: list[dict]
) -> str | None:
    #bucket_name = urlparse(output_s3_uri).netloc
    bucket_arn = None
    
    # if not bucket_name:
    #     print(f"  could not parse bucket from OUTPUT_S3_URI '{output_s3_uri}'; skipping S3 step")
    #     return None
    
    print(f"Ensuring S3 bucket '{bucket_name}'...")

    try:
        response = s3_client.head_bucket(Bucket=bucket_name)
        print("  bucket already exists")

        bucket_arn = response.get('BucketArn')
    except ClientError as e:
        code = e.response.get("Error", {}).get("Code")

        if code == "404":
            print("  bucket not found; creating it")

            response = s3_client.create_bucket(
                Bucket=bucket_name,
                CreateBucketConfiguration={ "LocationConstraint": region }
            )

            s3_client.get_waiter("bucket_exists").wait(Bucket=bucket_name)
            print(f"  created S3 bucket '{bucket_name}'")

            bucket_arn = response.get('BucketArn')
        else:
            raise

    # merge with any existing tags because put_bucket_tagging replaces all tags
    try:
        existing_bucket_tags = s3_client.get_bucket_tagging(Bucket=bucket_name).get("TagSet", [])
    except ClientError as e:
        if e.response.get("Error", {}).get("Code") == "NoSuchTagSet":
            existing_bucket_tags = []
        else:
            raise

    provided_bucket_tags = {tag["Key"] for tag in kv_tags}

    merged_bucket_tags = [
        t for t in existing_bucket_tags 
        if t["Key"] not in provided_bucket_tags
    ] + kv_tags

    s3_client.put_bucket_tagging(Bucket=bucket_name, Tagging={"TagSet": merged_bucket_tags})
    print(f"  tagged S3 bucket '{bucket_name}'")

    return bucket_arn
        

def get_or_create_sagemaker_execution_role(
    session: boto3.Session,
    s3_bucket_arn: str,
    tags: list[dict] = []
) -> str | None:
    iam_client = session.client('iam')

    role_arn = None

    try:
        response = iam_client.get_role(
            RoleName=SAGEMAKER_ROLE_NAME
        )

        role_arn = response.get('Role', {}).get('Arn')
    except iam_client.exceptions.NoSuchEntityException:
        pass

    if role_arn is None:
        policy_document = {
            "Version": "2012-10-17",
            "Statement": [
                {
                    "Effect": "Allow",
                    "Principal": {
                        "Service": "sagemaker.amazonaws.com",
                    },
                    "Action": "sts:AssumeRole"
                }
            ]
        }

        response = iam_client.create_role(
            Path=APP_ARN_PATH,
            RoleName=SAGEMAKER_ROLE_NAME,
            AssumeRolePolicyDocument=json.dumps(policy_document),
            Tags=tags
        )

        waiter = iam_client.get_waiter('role_exists')

        waiter.wait(
            RoleName=SAGEMAKER_ROLE_NAME,
            WaiterConfig={
                'Delay': 1,
                'MaxAttempts': 10
            }
        )

        role_arn = response.get("Role", {}).get("Arn")

    # ensure role has the required permissions to update bucket
    iam_client.put_role_policy(
        RoleName=SAGEMAKER_ROLE_NAME,
        PolicyName="SageMakerExecutionPolicy",
        PolicyDocument=json.dumps({
            "Version": "2012-10-17",
            "Statement": [
                {
                    "Action": [
                        "s3:ListBucket",
                        "s3:GetObject",
                        "s3:PutObject",
                        "s3:DeleteObject"
                    ],
                    "Effect": "Allow",
                    "Resource": s3_bucket_arn
                }
            ]
        })
    )

    return role_arn

def get_or_create_lambda_execution_role(
    session: boto3.Session,
    lambda_function_name: str,
    dynamodb_arns: list[str],
    invoke_lambda_arn: str = None,
    tags: list[dict] = [],
) -> str | None:
    iam_client = session.client('iam')

    role_name = f'{lambda_function_name}-{LAMBDA_ROLE_NAME}'
    role_arn = None

    # check if role already exists and get ARN
    try:
        response = iam_client.get_role(
            RoleName=role_name
        )

        role_arn = response.get('Role', {}).get('Arn')
    except iam_client.exceptions.NoSuchEntityException:
        pass

    # if no role ARN was obtained, create a new role
    if role_arn is None:
        role_policy_document = {
            "Version": "2012-10-17",
            "Statement": [
                {
                    "Effect": "Allow",
                    "Principal": {
                        "Service": "lambda.amazonaws.com"
                    },
                    "Action": "sts:AssumeRole"
                }
            ]
        }

        response = iam_client.create_role(
            Path=APP_ARN_PATH,
            RoleName=role_name,
            AssumeRolePolicyDocument=json.dumps(role_policy_document),
            Tags=tags
        )

        role_arn = response.get("Role", {}).get("Arn")

        waiter = iam_client.get_waiter('role_exists')

        waiter.wait(
            RoleName=role_name,
            WaiterConfig={
                'Delay': 1,
                'MaxAttempts': 10
            }
        )

    # define inline policies to attach to role
    policy_document = {
        "Version": "2012-10-17",
        "Statement": [
            # =================================
            # CloudWatch Permissions
            # =================================
            {
                "Sid": "CloudWatchLogs",
                "Effect": "Allow",
                "Action": [
                    "logs:CreateLogGroup",
                    "logs:CreateLogStream",
                    "logs:PutLogEvents"
                ],
                "Resource": "arn:aws:logs:*:*:*"
            },

            # =================================
            # DynamoDB Permissions
            # =================================
            {
                "Sid": "DynamoDB",
                "Effect": "Allow",
                "Action": [
                    "dynamodb:GetItem",
                    "dynamodb:PutItem",
                    "dynamodb:UpdateItem",
                    "dynamodb:DeleteItem",
                    "dynamodb:Query",
                    "dynamodb:DescribeTable"
                ],
                "Resource": dynamodb_arns
            },

            # =================================
            # SageMaker Permissions
            # =================================
            {
                "Sid": "SageMaker",
                "Effect": "Allow",
                "Action": [
                    "sagemaker:CreateModel",
                    "sagemaker:DescribeModel",
                    "sagemaker:CreateTransformJob",
                    "sagemaker:ListTransformJobs",
                    "sagemaker:DescribeTransformJob",
                    "sagemaker:AddTags"
                ],
                "Resource": "*"
            }
        ],
    }

    if invoke_lambda_arn is not None:
        policy_document["Statement"].append({
            "Sid": "InvokeNextLambda",
            "Effect": "Allow",
            "Action": "lambda:InvokeFunction",
            "Resource": invoke_lambda_arn
        })

    iam_client.put_role_policy(
        RoleName=role_name,
        PolicyName="LambdaExecutionPolicy",
        PolicyDocument=json.dumps(policy_document)
    )

    return role_arn

def main() -> None:
    print(f"AWS deploy directory: {AWS_DIR}")
    settings = load_settings()

    session = boto3.Session(region_name=settings.AWS_REGION)

    sts_client = session.client("sts")

    try:
        caller_identity = sts_client.get_caller_identity()
    except Exception as e:
        print("Failed to retrieve IAM user/role details of the current user.\n")
        print(*traceback.format_exception_only(e))
        return

    account_number = caller_identity.get('Account')
    user_id = caller_identity.get('UserId')

    if account_number is None or user_id is None:
        raise Exception("Could not obtain a valid AWS account number and/or user ID.")

    print('Running AWS deploy script with the following credentials:')
    print(f'Account: {account_number}')
    print(f'User ID: {user_id}\n')
    decision = input("Proceed? [Y/n]: ")

    if decision != 'Y':
        print('\nDeploy canceled.')
        return

    print('\nDeploying AWS resources...\n')

    lambda_client = session.client("lambda")
    events_client = session.client("events")
    dynamodb_client = session.client("dynamodb")
    s3_client = session.client("s3")

    kv_tags = [{ "Key": "AppName", "Value": settings.APP_NAME }]

    # =================================
    # 1. Ensure DynamoDB table exists
    # =================================
    table_arn = get_or_create_dynamodb_table(
        dynamodb_client, 
        settings.LO_MAPPING_DYNAMODB_REQUESTS_TABLE, 
        kv_tags
    )

    if table_arn is None:
        raise Exception("Failed to get or create DynamoDB table.")

    # ===========================
    # 2. Ensure S3 bucket exists
    # ===========================
    s3_bucket_name = generate_s3_bucket_name(
        settings.S3_BUCKET_BASE,
        account_number
    )

    s3_bucket_arn = get_or_create_s3_bucket(
        s3_client,
        s3_bucket_name,
        settings.AWS_REGION,
        kv_tags
    )

    # ==========================================
    # 3. Prepare lambda handler code for upload
    # ==========================================
    start_zip, process_zip = zip_handlers()

    # ====================================
    # 4. Define SageMaker execution role
    # ====================================
    sagemaker_role_arn = get_or_create_sagemaker_execution_role(
        session,
        s3_bucket_arn,
        kv_tags
    )

    # ==================================================================
    # 4. Create the 'Start Job' Lambda function
    # NOTE: This job is the only one that interacts with SageMaker
    # ==================================================================
    start_lambda_role_arn = get_or_create_lambda_execution_role(
        session,
        START_LAMBDA_FUNCTION_NAME,
        [
            table_arn,
            table_arn.rstrip("/") + "/index/*"
        ],
        None,
        kv_tags
    )

    start_lambda_function_arn = get_or_create_start_lambda(
        lambda_client,
        settings,
        start_zip,
        start_lambda_role_arn,
        sagemaker_role_arn,
        s3_bucket_name,
        kv_tags
    )

    # ================================================
    # 5. Create the 'Process Results' Lambda function
    # ================================================
    process_lambda_role_arn = get_or_create_lambda_execution_role(
        session,
        PROCESS_LAMBDA_FUNCTION_NAME,
        [
            table_arn,
            table_arn.rstrip("/") + "/index/*"
        ],
        start_lambda_function_arn,
        kv_tags
    )

    process_lambda_function_arn = get_or_create_process_lambda(
        lambda_client,
        settings,
        process_zip,
        process_lambda_role_arn,
        kv_tags
    )

    # ===================================================
    # 6. Print details of the created Lambda functions
    # ===================================================
    log_lambdas(
        lambda_client,
        [process_lambda_function_arn, start_lambda_function_arn]
    )

    setup_eventbridge(
        settings,
        events_client,
        lambda_client,
        sts_client,
        kv_tags
    )

    print("\nDone.")


if __name__ == "__main__":
    main()