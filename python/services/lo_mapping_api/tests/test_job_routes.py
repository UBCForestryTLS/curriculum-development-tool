from datetime import UTC, datetime
from unittest.mock import Mock

import pytest
from botocore.exceptions import ClientError, EndpointConnectionError
from fastapi.testclient import TestClient

from app import job_routes
from app.api_key_store import ApiKeyStore
from app.api_keys import generate_api_key
from app.bedrock_client import BedrockClient
from app.job_store import MappingJobStore
from app.main import app


@pytest.fixture
def client(table, monkeypatch, tmp_path):
    # Use the real API key check and job store, with the shared simulated table.
    monkeypatch.chdir(tmp_path)
    monkeypatch.setenv("AWS_REGION", "ca-central-1")
    monkeypatch.setenv("LO_MAPPING_DYNAMODB_TABLE", table.name)
    credentials = generate_api_key()
    ApiKeyStore(table).create(credentials)
    monkeypatch.setattr(
        BedrockClient,
        "generate_text",
        Mock(side_effect=AssertionError("Saving or reading a job must not call Bedrock.")),
    )
    headers = {
        "X-API-Key-ID": credentials.key_id,
        "X-API-Key-Secret": credentials.secret,
    }
    with TestClient(app, headers=headers) as client:
        yield client


def test_create_and_get_return_public_job_information(client, table, job_payload):
    started = datetime.now(UTC)
    response = client.post("/jobs", json=job_payload)

    assert response.status_code == 200
    created = response.json()
    # Checking the full response also catches internal fields accidentally being exposed.
    assert created == {
        "job_id": job_payload["job_id"],
        "course_id": 4,
        "program_id": 9,
        "total_clos": 2,
        "total_plos": 3,
        "total_pairs": 6,
        "processed_pairs": 0,
        "mapped_pairs": 0,
        "errored_pairs": 0,
        "status": "pending",
        "created_at": created["created_at"],
        "pairs_ready": True,
    }
    assert started <= datetime.fromisoformat(created["created_at"]) <= datetime.now(UTC)
    assert len(MappingJobStore(table).get_pairs(job_payload["job_id"])) == 6

    response = client.get(f"/jobs/{job_payload['job_id']}")

    assert response.status_code == 200
    assert response.json() == created


def test_repeated_creation_keeps_existing_progress(client, table, job_payload):
    first = client.post("/jobs", json=job_payload)
    assert first.status_code == 200
    store = MappingJobStore(table)
    record = store.get(job_payload["job_id"])
    # Stand in for processing that happened before Laravel repeated the request.
    record.update(status="in_progress", processed_pairs=1, mapped_pairs=1)
    table.put_item(Item=record)
    saved_pairs = store.get_pairs(job_payload["job_id"])

    repeated = client.post("/jobs", json=job_payload)

    assert repeated.status_code == 200
    assert repeated.json() == {
        **first.json(), "status": "in_progress", "processed_pairs": 1, "mapped_pairs": 1,
    }
    assert store.get_pairs(job_payload["job_id"]) == saved_pairs


def test_conflicting_creation_returns_409_without_changing_the_job(client, job_payload):
    first = client.post("/jobs", json=job_payload)
    assert first.status_code == 200

    response = client.post("/jobs", json={**job_payload, "course_id": 99})

    assert response.status_code == 409
    assert response.json() == {"detail": "Job ID already exists with different inputs."}
    assert client.get(f"/jobs/{job_payload['job_id']}").json() == first.json()


@pytest.mark.parametrize("method", ["post", "get"])
@pytest.mark.parametrize("credentials", ["missing", "incorrect"])
def test_job_routes_reject_invalid_credentials_before_accessing_jobs(
    client, job_payload, monkeypatch, method, credentials
):
    if credentials == "missing":
        del client.headers["X-API-Key-Secret"]
    else:
        client.headers["X-API-Key-Secret"] = "incorrect"
    store_constructor = Mock(side_effect=AssertionError("An unauthenticated request reached job storage."))
    monkeypatch.setattr(job_routes, "MappingJobStore", store_constructor)

    if method == "post":
        response = client.post("/jobs", json=job_payload)
    else:
        response = client.get(f"/jobs/{job_payload['job_id']}")

    assert response.status_code == 401
    assert response.json() == {"detail": "Invalid or missing API credentials."}
    store_constructor.assert_not_called()


@pytest.mark.parametrize(
    ("field", "value"),
    [("job_id", "not-a-uuid"), ("course_learning_outcomes", []), ("status", "completed")],
)
def test_invalid_job_inputs_return_422_before_accessing_storage(
    client, job_payload, monkeypatch, field, value
):
    store_constructor = Mock(side_effect=AssertionError("Invalid inputs reached job storage."))
    monkeypatch.setattr(job_routes, "MappingJobStore", store_constructor)

    response = client.post("/jobs", json={**job_payload, field: value})

    assert response.status_code == 422
    assert response.json()["detail"][0]["loc"] == ["body", field]
    store_constructor.assert_not_called()


def test_get_returns_404_for_a_missing_job(client, job_payload):
    response = client.get(f"/jobs/{job_payload['job_id']}")

    assert response.status_code == 404
    assert response.json() == {"detail": "Mapping job not found."}


def test_get_rejects_invalid_job_ids_before_accessing_storage(client, monkeypatch):
    store_constructor = Mock(side_effect=AssertionError("An invalid job ID reached storage."))
    monkeypatch.setattr(job_routes, "MappingJobStore", store_constructor)

    response = client.get("/jobs/not-a-uuid")

    assert response.status_code == 422
    assert response.json()["detail"][0]["loc"] == ["path", "job_id"]
    store_constructor.assert_not_called()


@pytest.mark.parametrize("operation", ["create", "get"])
@pytest.mark.parametrize(
    "storage_error",
    [
        ClientError(
            {"Error": {"Code": "AccessDeniedException", "Message": "private-storage-details"}},
            "GetItem",
        ),
        EndpointConnectionError(endpoint_url="https://private-storage-details.invalid"),
    ],
    ids=["aws-error", "connection-error"],
)
def test_storage_failures_return_503_without_exposing_details(
    client, job_payload, monkeypatch, operation, storage_error
):
    # Authentication still succeeds; only the job storage operation fails.
    monkeypatch.setattr(MappingJobStore, operation, Mock(side_effect=storage_error))

    if operation == "create":
        response = client.post("/jobs", json=job_payload)
    else:
        response = client.get(f"/jobs/{job_payload['job_id']}")

    assert response.status_code == 503
    assert response.json() == {"detail": "Job storage is unavailable."}
