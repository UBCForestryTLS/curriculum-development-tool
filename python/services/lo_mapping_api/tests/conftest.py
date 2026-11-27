import boto3
import pytest
from moto import mock_aws

from app.job_models import MappingJobRequest


@pytest.fixture
def table(monkeypatch, tmp_path):
    # Use fake credentials and an isolated AWS setup, without reading our real login.
    monkeypatch.setenv("AWS_ACCESS_KEY_ID", "testing")
    monkeypatch.setenv("AWS_SECRET_ACCESS_KEY", "testing")
    monkeypatch.setenv("AWS_SESSION_TOKEN", "testing")
    monkeypatch.setenv("AWS_CONFIG_FILE", str(tmp_path / "aws-config"))
    monkeypatch.setenv("AWS_SHARED_CREDENTIALS_FILE", str(tmp_path / "aws-credentials"))
    for name in (
        "AWS_PROFILE",
        "AWS_DEFAULT_PROFILE",
        "AWS_ENDPOINT_URL",
        "AWS_ENDPOINT_URL_DYNAMODB",
    ):
        monkeypatch.delenv(name, raising=False)

    with mock_aws():
        yield boto3.resource("dynamodb", region_name="ca-central-1").create_table(
            TableName="lo-mapping-api-keys-test",
            KeySchema=[
                {"AttributeName": "pk", "KeyType": "HASH"},
                {"AttributeName": "sk", "KeyType": "RANGE"},
            ],
            AttributeDefinitions=[
                {"AttributeName": "pk", "AttributeType": "S"},
                {"AttributeName": "sk", "AttributeType": "S"},
            ],
            BillingMode="PAY_PER_REQUEST",
        )


@pytest.fixture
def job_payload():
    # Two CLOs and three PLOs give us six pairs to check when storing a job.
    return {
        "job_id": "7046a4db-9b47-4861-9b75-91c429d5db02",
        "course_id": 4,
        "program_id": 9,
        "course_learning_outcomes": [
            {"l_outcome_id": 21, "l_outcome": '  Explain "forest health".\nUse forêt examples.  '},
            {"l_outcome_id": 22, "l_outcome": "Apply ecological principles."},
        ],
        "program_learning_outcomes": [
            {"pl_outcome_id": 34, "pl_outcome": "Understand ecosystems."},
            {"pl_outcome_id": 35, "pl_outcome": "Apply forest management practices."},
            {"pl_outcome_id": 36, "pl_outcome": "Evaluate ecological evidence."},
        ],
        "mapping_scales": [
            {
                "map_scale_id": 3,
                "title": "Foundation",
                "abbreviation": "FOUND",
                "description": "Explain concepts.",
            },
            {
                "map_scale_id": 8,
                "title": "Application",
                "abbreviation": "APPLY",
                "description": "Apply concepts.",
            },
        ],
    }


@pytest.fixture
def job_request(job_payload):
    return MappingJobRequest.model_validate(job_payload)
