"""
Similar to main.py, but points AWS env vars at LocalStack. Used for E2E tests.
If LocalStack isn't already running, starts it (and stops it on exit)
! Don't deploy this to production !
"""

import atexit
import os
import time

import boto3
import requests
import uvicorn

from dotenv import load_dotenv

from testcontainers.localstack import LocalStackContainer
from testcontainers.core.waiting_utils import wait_for_logs

FASTAPI_PORT = 8002
LOCALSTACK_PORT = 4566
LARAVEL_TEST_PORT = 8010 # End to end test laravel server runs on this port, different from normal 8000
LOCALSTACK_HEALTH_URL = f"http://127.0.0.1:{LOCALSTACK_PORT}/_localstack/health"

TEST_ENV = {
    "AWS_ENDPOINT_URL":                   f"http://127.0.0.1:{LOCALSTACK_PORT}",
    "AWS_ACCESS_KEY_ID":                  "test",
    "AWS_SECRET_ACCESS_KEY":              "test",
    "ACCESS_KEY":                         "test",
    "SECRET_KEY":                         "test",
    "LARAVEL_API_URL":                    f"http://127.0.0.1:{LARAVEL_TEST_PORT}/api/microservices/lo-mapping/ai-suggestions/store",
}

os.environ.update(TEST_ENV)

# We have to import these after TEST_ENV is applied, 
# so the app's boto clients point at LocalStack,  
# because routes.py creates the boto client at the module level.
# To clean this up, we should refactor routes.py and some of the files it imports
from app.api.routes import app
from app.api.test_routes import register_test_routes 
# We have to import Settings after TEST_ENV is applied,
# so it uses the LARAVEL_API_URL with the test port instead of the actual one
from app.core.config import Settings

def is_localstack_running() -> bool:
    try:
        return requests.get(LOCALSTACK_HEALTH_URL, timeout=2).status_code == 200
    except requests.RequestException:
        return False

def ensure_localstack_running():
    if is_localstack_running(): # Could be running from another service's test
        print(f"[test] LocalStack already running on {LOCALSTACK_PORT}; reusing it")
    else:
        print("[test] Starting LocalStack container")
        container = LocalStackContainer(image="localstack/localstack:3.0")
        container.with_bind_ports(LOCALSTACK_PORT, LOCALSTACK_PORT)
        container.start()
        
        atexit.register(container.stop) # If this test started the LocalStack container, stop it on exit
        
        wait_for_logs(container, r"Ready\.", timeout=60)
        print(f"[test] LocalStack ready on {LOCALSTACK_PORT}")

def initialize_storage():
    """Create the S3 bucket the service uses in LocalStack."""
    settings = Settings()
    region = settings.AWS_REGION or "ca-central-1"
    bucket = settings.BATCH_TRANSFORM_INPUT_S3_BUCKET
    if not bucket:
        raise RuntimeError("BATCH_TRANSFORM_INPUT_S3_BUCKET is not set in .env")

    s3 = boto3.client(
        "s3",
        endpoint_url=f"http://127.0.0.1:{LOCALSTACK_PORT}",
        region_name=region,
        aws_access_key_id="test",
        aws_secret_access_key="test",
    )
    try:
        s3.create_bucket(
            Bucket=bucket,
            CreateBucketConfiguration={"LocationConstraint": region},
        )
        print(f"[test] Created S3 bucket {bucket}")
    except s3.exceptions.BucketAlreadyOwnedByYou:
        print(f"[test] S3 bucket {bucket} already exists, reusing")


def reset_dynamodb():
    """Drop the LO mapping requests table if it exists."""
    from app.services import LOMappingRequestDynamoDBRecord
    store = LOMappingRequestDynamoDBRecord()
    client = store._create_boto_session().client("dynamodb", region_name=store.aws_region)
    try:
        client.delete_table(TableName=store.table_name)
        print(f"[test] Dropped DynamoDB table {store.table_name}")
    except client.exceptions.ResourceNotFoundException:
        print(f"[test] DynamoDB table {store.table_name} did not exist, nothing to drop")


def start_fastapi():
    # Never register these in main.py or deploy this to production
    register_test_routes(app)

    print(f"[test] Starting FastAPI on port {FASTAPI_PORT} (Ctrl+C to stop)")
    config = uvicorn.Config(
        app,
        host="127.0.0.1",
        port=FASTAPI_PORT,
        log_level="info",
    )
    uvicorn.Server(config).run()


if __name__ == "__main__":
    ensure_localstack_running()
    reset_dynamodb()
    initialize_storage()
    start_fastapi()
