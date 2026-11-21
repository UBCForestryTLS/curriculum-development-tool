import json
from unittest.mock import Mock

import pytest
from botocore.exceptions import ClientError, EndpointConnectionError, NoCredentialsError
from fastapi import Depends
from fastapi.testclient import TestClient

from app import auth
from app.api_key_store import ApiKeyStore
from app.api_keys import generate_api_key
from app.handler import handler
from app.main import app


@pytest.fixture
def protected_app(table, monkeypatch, tmp_path):
    monkeypatch.chdir(tmp_path)
    monkeypatch.setenv("AWS_REGION", "ca-central-1")
    monkeypatch.setenv("LO_MAPPING_DYNAMODB_TABLE", table.name)
    # Add a route just for checking authentication, and restore the real routes afterwards.
    monkeypatch.setattr(app.router, "routes", app.router.routes.copy())

    @app.get("/test-auth")
    def protected_route(key_id: str = Depends(auth.require_api_key)):
        return {"key_id": key_id}

    return app


@pytest.fixture
def client(protected_app):
    with TestClient(protected_app) as client:
        yield client


@pytest.fixture
def api_headers(table):
    credentials = generate_api_key()
    ApiKeyStore(table).create(credentials)
    return {
        "X-API-Key-ID": credentials.key_id,
        "X-API-Key-Secret": credentials.secret,
    }


def assert_unauthorized(response):
    assert response.status_code == 401
    assert response.json() == {"detail": "Invalid or missing API credentials."}
    assert response.headers["www-authenticate"] == "APIKey"


@pytest.mark.parametrize("lowercase_headers", [False, True])
def test_valid_credentials_provide_only_the_verified_key_id(client, api_headers, lowercase_headers):
    headers = api_headers
    if lowercase_headers:
        headers = {name.lower(): value for name, value in headers.items()}

    response = client.get("/test-auth", headers=headers)

    assert response.status_code == 200
    assert response.json() == {"key_id": api_headers["X-API-Key-ID"]}


@pytest.mark.parametrize("header_name", ["X-API-Key-ID", "X-API-Key-Secret"])
@pytest.mark.parametrize("value", [None, "", "   "], ids=["missing", "empty", "whitespace"])
def test_missing_or_blank_credentials_are_rejected_before_accessing_aws(
    client, api_headers, monkeypatch, header_name, value
):
    store_constructor = Mock(side_effect=AssertionError("Should not access AWS"))
    monkeypatch.setattr(auth, "ApiKeyStore", store_constructor)
    headers = api_headers.copy()
    if value is None:
        headers.pop(header_name)
    else:
        headers[header_name] = value

    assert_unauthorized(client.get("/test-auth", headers=headers))
    store_constructor.assert_not_called()


def test_credentials_in_query_parameters_are_not_accepted(client, api_headers, monkeypatch):
    store_constructor = Mock(side_effect=AssertionError("Should not access AWS"))
    monkeypatch.setattr(auth, "ApiKeyStore", store_constructor)

    assert_unauthorized(client.get("/test-auth", params=api_headers))
    store_constructor.assert_not_called()


@pytest.mark.parametrize("header_name", ["X-API-Key-ID", "X-API-Key-Secret"])
def test_unknown_ids_and_wrong_secrets_get_the_same_response(client, api_headers, header_name):
    headers = {**api_headers, header_name: "incorrect"}

    assert_unauthorized(client.get("/test-auth", headers=headers))


def test_secret_must_belong_to_the_requested_key(client, api_headers, table):
    other_credentials = generate_api_key()
    ApiKeyStore(table).create(other_credentials)
    headers = {**api_headers, "X-API-Key-Secret": other_credentials.secret}

    assert_unauthorized(client.get("/test-auth", headers=headers))


def test_revocation_rejects_a_previously_accepted_key(client, api_headers, table):
    assert client.get("/test-auth", headers=api_headers).status_code == 200

    ApiKeyStore(table).revoke(api_headers["X-API-Key-ID"])

    # Reuse the same client so a previous successful check cannot keep the key working.
    assert_unauthorized(client.get("/test-auth", headers=api_headers))


@pytest.mark.parametrize("stored_hash", [None, 123, "not-a-hash"])
def test_invalid_stored_credentials_are_rejected(client, api_headers, table, stored_hash):
    record = {"pk": f"API_KEY#{api_headers['X-API-Key-ID']}", "sk": "METADATA"}
    if stored_hash is not None:
        record["secret_hash"] = stored_hash
    table.put_item(Item=record)

    assert_unauthorized(client.get("/test-auth", headers=api_headers))


@pytest.mark.parametrize(
    "aws_error",
    [
        ClientError(
            {"Error": {"Code": "AccessDeniedException", "Message": "sensitive-request-details"}},
            "GetItem",
        ),
        EndpointConnectionError(endpoint_url="https://sensitive-request-details.invalid"),
    ],
    ids=["access-denied", "connection-failed"],
)
def test_lookup_failures_return_a_service_error_without_aws_details(
    client, api_headers, monkeypatch, aws_error
):
    monkeypatch.setattr(ApiKeyStore, "get", Mock(side_effect=aws_error))

    response = client.get("/test-auth", headers=api_headers)

    assert response.status_code == 503
    assert response.json() == {"detail": "Authentication service is unavailable."}


def test_missing_aws_credentials_return_a_service_error(client, api_headers, monkeypatch):
    monkeypatch.setattr(auth, "ApiKeyStore", Mock(side_effect=NoCredentialsError()))

    response = client.get("/test-auth", headers=api_headers)

    assert response.status_code == 503
    assert response.json() == {"detail": "Authentication service is unavailable."}


def test_missing_table_is_not_treated_as_an_unknown_key(client, api_headers, table):
    table.delete()

    response = client.get("/test-auth", headers=api_headers)

    assert response.status_code == 503
    assert response.json() == {"detail": "Authentication service is unavailable."}


def test_missing_configuration_returns_a_service_error(client, api_headers, monkeypatch):
    monkeypatch.delenv("LO_MAPPING_DYNAMODB_TABLE")

    response = client.get("/test-auth", headers=api_headers)

    assert response.status_code == 503
    assert response.json() == {"detail": "Authentication service is unavailable."}


@pytest.mark.parametrize("valid_secret", [True, False], ids=["valid", "incorrect"])
def test_lambda_authenticates_http_api_requests(protected_app, api_headers, valid_secret):
    # API Gateway lowercases header names. Use its event shape with the real Lambda handler.
    headers = {name.lower(): value for name, value in api_headers.items()}
    if not valid_secret:
        headers["x-api-key-secret"] = "incorrect"
    event = {
        "version": "2.0",
        "routeKey": "$default",
        "rawPath": "/test-auth",
        "rawQueryString": "",
        "headers": {
            "host": "example.execute-api.ca-central-1.amazonaws.com",
            "x-forwarded-proto": "https",
            **headers,
        },
        "requestContext": {
            "stage": "$default",
            "http": {
                "method": "GET",
                "path": "/test-auth",
                "sourceIp": "127.0.0.1",
                "protocol": "HTTP/1.1",
            },
        },
        "isBase64Encoded": False,
    }

    response = handler(event, None)

    if valid_secret:
        assert response["statusCode"] == 200
        assert json.loads(response["body"]) == {"key_id": api_headers["X-API-Key-ID"]}
    else:
        assert response["statusCode"] == 401
        assert json.loads(response["body"]) == {"detail": "Invalid or missing API credentials."}
        assert response["headers"]["www-authenticate"] == "APIKey"
