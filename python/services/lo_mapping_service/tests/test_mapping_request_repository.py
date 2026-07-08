import pytest
import boto3
from moto import mock_aws
from app.core.config import Settings
from app.services.lo_mapping_request_dynamo_db_request import LOMappingRequestDynamoDBRecord



TABLE_NAME = "test-lo-mapping-requests-table"
AWS_REGION = "ca-central-1"
OUTPUT_S3_URI = "s3://test-bucket/output/"


@pytest.fixture(autouse=True)
def env_vars(monkeypatch):
    """Set required environment variables for all tests."""
    monkeypatch.setenv("LO_MAPPING_DYNAMODB_REQUESTS_TABLE", TABLE_NAME)
    monkeypatch.setenv("AWS_REGION", AWS_REGION)
    monkeypatch.setenv("ACCESS_KEY", "fake-access-key")
    monkeypatch.setenv("SECRET_KEY", "fake-secret-key")
    monkeypatch.setenv("OUTPUT_S3_URI", OUTPUT_S3_URI)
    # Make sure a leaked AWS_ENDPOINT_URL from another test file doesn't
    # redirect boto3 away from moto's mock.
    monkeypatch.delenv("AWS_ENDPOINT_URL", raising=False)


@pytest.fixture
def record():
    """Return a fresh instance of the class under test."""
    return LOMappingRequestDynamoDBRecord()

class TestLOMappingRequestDynamoDBRecordEnsureTableExists:
    def test_uses_pydantic_settings_object(self):
        settings = Settings(
            LO_MAPPING_DYNAMODB_REQUESTS_TABLE="custom-table",
            AWS_REGION=AWS_REGION,
            ACCESS_KEY="custom-access-key",
            SECRET_KEY="custom-secret-key",
            DYNAMODB_STATUS_INDEX="custom-status-index",
        )

        record = LOMappingRequestDynamoDBRecord(settings=settings)

        assert record.table_name == "custom-table"
        assert record.aws_region == AWS_REGION
        assert record.aws_access_key == "custom-access-key"
        assert record.aws_secret_key == "custom-secret-key"
        assert record.status_index == "custom-status-index"

    def test_raises_if_table_name_not_set(self, record):
        record.table_name = None
        with pytest.raises(ValueError, match="LO_MAPPING_DYNAMODB_REQUESTS_TABLE is not set"):
            record.ensure_table_exists()

    @mock_aws
    def test_creates_table_when_not_exists(self, record):
        record.ensure_table_exists()

        client = boto3.client("dynamodb", region_name=AWS_REGION)
        response = client.describe_table(TableName=TABLE_NAME)
        assert response["Table"]["TableName"] == TABLE_NAME

    @mock_aws
    def test_created_table_has_correct_key_schema(self, record):
        record.ensure_table_exists()

        client = boto3.client("dynamodb", region_name=AWS_REGION)
        table_desc = client.describe_table(TableName=TABLE_NAME)["Table"]
        key_schema = table_desc["KeySchema"]

        assert {"AttributeName": "request_id", "KeyType": "HASH"} in key_schema

    @mock_aws
    def test_created_table_has_gsi(self, record):
        record.ensure_table_exists()

        client = boto3.client("dynamodb", region_name=AWS_REGION)
        table_desc = client.describe_table(TableName=TABLE_NAME)["Table"]
        gsi_names = [gsi["IndexName"] for gsi in table_desc.get("GlobalSecondaryIndexes", [])]

        assert "status-created_at-index" in gsi_names

    @mock_aws
    def test_existing_table_is_not_recreated(self, record):
        record.ensure_table_exists()

        client = boto3.client("dynamodb", region_name=AWS_REGION)
        tables_before = client.list_tables()["TableNames"]

        record.ensure_table_exists()

        tables_after = client.list_tables()["TableNames"]
        assert tables_before == tables_after


class TestLOMappingRequestDynamoDBRecordCreateRequest:

    def test_raises_if_table_name_not_set(self, record):
        record.table_name = None
        with pytest.raises(ValueError, match="LO_MAPPING_DYNAMODB_REQUESTS_TABLE is not set"):
            record.create_request(1, 2, "s3://bucket/batch_inputs/file.json")

    def test_raises_if_input_s3_path_empty(self, record):
        with pytest.raises(ValueError, match="input_s3_path is required"):
            record.create_request(1, 2, "")

    def test_raises_if_input_s3_path_none(self, record):
        with pytest.raises(ValueError, match="input_s3_path is required"):
            record.create_request(1, 2, None)

    @mock_aws
    def test_returns_item_with_correct_fields(self, record):
        record.ensure_table_exists()
        item = record.create_request(
            course_id=101,
            program_id=202,
            input_s3_path="s3://bucket/batch_inputs/file.json",
        )

        assert "request_id" in item
        assert item["course_id"] == 101
        assert item["program_id"] == 202
        assert item["status"] == "PENDING"
        assert item["input_s3_path"] == "s3://bucket/batch_inputs/file.json"
        assert item["output_s3_path"] == f"{OUTPUT_S3_URI.rstrip('/')}/file.json.out"
        assert "created_at" in item

    @mock_aws
    def test_request_id_is_unique(self, record):
        record.ensure_table_exists()
        item1 = record.create_request(1, 2, "s3://bucket/batch_inputs/a.json")
        item2 = record.create_request(1, 2, "s3://bucket/batch_inputs/b.json")
        assert item1["request_id"] != item2["request_id"]

    @mock_aws
    def test_custom_status_does_not_use_default(self, record):
        record.ensure_table_exists()
        item = record.create_request(
            course_id=1,
            program_id=2,
            input_s3_path="s3://bucket/batch_inputs/file.json",
            status="IN_PROGRESS",
        )
        assert item["status"] == "IN_PROGRESS"

        dynamodb = boto3.resource("dynamodb", region_name=AWS_REGION)
        stored = dynamodb.Table(TABLE_NAME).get_item(
            Key={"request_id": item["request_id"]}
        )["Item"]
        assert stored["status"] == "IN_PROGRESS"

    @mock_aws
    def test_raises_runtime_error_on_dynamodb_failure(self, record):
        # Table does NOT exist — put_item will fail
        with pytest.raises(RuntimeError, match="Failed to create LO mapping request record"):
            record.create_request(1, 2, "s3://bucket/batch_inputs/file.json")