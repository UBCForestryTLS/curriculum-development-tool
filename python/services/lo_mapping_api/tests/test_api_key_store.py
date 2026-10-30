import json
from datetime import UTC, datetime
from unittest.mock import Mock

import boto3
import pytest
from botocore.exceptions import ClientError, EndpointConnectionError
from moto import mock_aws

from app.api_key_store import ApiKeyStore
from app.api_keys import ApiKeyCredentials, generate_api_key, verify_api_key_secret


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


def test_create_stores_only_the_hash_and_creation_time(table):
    credentials = generate_api_key()
    before_creation = datetime.now(UTC)
    record = ApiKeyStore(table).create(credentials)

    stored = table.get_item(
        Key={"pk": f"API_KEY#{credentials.key_id}", "sk": "METADATA"}
    )["Item"]

    assert stored == record
    assert set(stored) == {"pk", "sk", "secret_hash", "created_at"}
    assert credentials.secret not in json.dumps(stored)
    assert verify_api_key_secret(credentials.secret, stored["secret_hash"])
    created_at = datetime.fromisoformat(stored["created_at"])
    assert before_creation <= created_at <= datetime.now(UTC)


def test_get_returns_the_requested_key_or_none(table):
    store = ApiKeyStore(table)
    first, second = generate_api_key(), generate_api_key()
    first_record = store.create(first)
    second_record = store.create(second)

    assert store.get(first.key_id) == first_record
    assert store.get(second.key_id) == second_record
    assert store.get("unknown-key") is None


def test_duplicate_creation_preserves_the_original_credential(table):
    store = ApiKeyStore(table)
    credentials = generate_api_key()
    original = store.create(credentials)
    replacement = ApiKeyCredentials(credentials.key_id, generate_api_key().secret)

    with pytest.raises(ClientError) as error:
        store.create(replacement)

    assert error.value.response["Error"]["Code"] == "ConditionalCheckFailedException"
    assert store.get(credentials.key_id) == original


def test_create_rejects_blank_secrets_without_storing_a_record(table):
    store = ApiKeyStore(table)
    credentials = ApiKeyCredentials(generate_api_key().key_id, "")

    with pytest.raises(ValueError, match="API key secret must not be blank"):
        store.create(credentials)

    assert store.get(credentials.key_id) is None


def test_revoke_removes_only_the_requested_key_and_can_be_repeated(table):
    store = ApiKeyStore(table)
    first, second = generate_api_key(), generate_api_key()
    store.create(first)
    second_record = store.create(second)

    assert store.revoke(first.key_id) is True
    assert store.get(first.key_id) is None
    assert store.get(second.key_id) == second_record
    assert store.revoke(first.key_id) is False


def test_get_requests_a_strongly_consistent_read(table, monkeypatch):
    # Moto does not simulate stale reads, so check this option explicitly.
    get_item = Mock(wraps=table.get_item)
    monkeypatch.setattr(table, "get_item", get_item)

    ApiKeyStore(table).get("unknown-key")

    assert get_item.call_args.kwargs["ConsistentRead"] is True


def test_store_uses_the_configured_region_and_table(table, monkeypatch, tmp_path):
    monkeypatch.chdir(tmp_path)
    monkeypatch.setenv("AWS_REGION", "ca-central-1")
    monkeypatch.setenv("LO_MAPPING_DYNAMODB_TABLE", table.name)
    credentials = generate_api_key()

    record = ApiKeyStore().create(credentials)

    assert ApiKeyStore(table).get(credentials.key_id) == record


@pytest.mark.parametrize("operation", ["create", "get", "revoke"])
def test_missing_table_errors_are_not_treated_as_missing_credentials(table, operation):
    store = ApiKeyStore(table)
    credentials = generate_api_key()
    table.delete()
    argument = credentials if operation == "create" else credentials.key_id

    with pytest.raises(ClientError) as error:
        getattr(store, operation)(argument)

    assert error.value.response["Error"]["Code"] == "ResourceNotFoundException"


def test_connection_errors_are_not_treated_as_missing_credentials(table, monkeypatch):
    connection_error = EndpointConnectionError(endpoint_url="https://dynamodb.invalid")
    monkeypatch.setattr(table, "get_item", Mock(side_effect=connection_error))

    with pytest.raises(EndpointConnectionError):
        ApiKeyStore(table).get("example-key")


@pytest.mark.parametrize("operation", ["create", "get", "revoke"])
@pytest.mark.parametrize("key_id", ["", "   "])
def test_store_rejects_blank_key_ids(table, operation, key_id):
    argument = key_id
    if operation == "create":
        argument = ApiKeyCredentials(key_id, generate_api_key().secret)

    with pytest.raises(ValueError, match="API key ID must not be blank"):
        getattr(ApiKeyStore(table), operation)(argument)
