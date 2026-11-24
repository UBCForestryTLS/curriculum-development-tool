import json
from datetime import UTC, datetime
from unittest.mock import Mock

import pytest
from botocore.exceptions import ClientError, EndpointConnectionError, ReadTimeoutError
from botocore.httpsession import URLLib3Session
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
    # Moto handles this locally - network guard: fail if a test accidentally tries a real AWS request
    monkeypatch.setattr(URLLib3Session, "send", Mock(side_effect=AssertionError("No real AWS calls in tests")))
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


@pytest.fixture
def model_calls(client, monkeypatch):
    monkeypatch.setenv("LO_MAPPING_BEDROCK_REGION", "us-west-2")
    monkeypatch.setenv("LO_MAPPING_BEDROCK_MODEL_ID", "test-model")
    monkeypatch.setenv("LO_MAPPING_BATCH_SIZE", "4")
    monkeypatch.setenv("LO_MAPPING_CONCURRENCY", "2")
    # Keep timing predictable here; the runner tests cover reaching the time cutoff.
    monkeypatch.setattr("app.batch_runner.monotonic", lambda: 0.0)

    def reply(prompt):
        # Only replace the model's answer. The route, prompt, parser and storage still run.
        pair = json.loads(prompt[prompt.index("{"):])
        return json.dumps({
            "id": pair["id"],
            "is_mapped": True,
            "mapLabels": ["FOUND"],
            "explanation": "The outcomes align.",
        })

    calls = Mock(side_effect=reply)
    monkeypatch.setattr(BedrockClient, "generate_text", calls)
    return calls


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


@pytest.mark.parametrize("method", ["post", "get", "run"])
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
    batch_runner = Mock(side_effect=AssertionError("An unauthenticated request started processing."))
    monkeypatch.setattr(job_routes, "run_batch", batch_runner)

    if method == "post":
        response = client.post("/jobs", json=job_payload)
    elif method == "run":
        response = client.post(f"/jobs/{job_payload['job_id']}/run")
    else:
        response = client.get(f"/jobs/{job_payload['job_id']}")

    assert response.status_code == 401
    assert response.json() == {"detail": "Invalid or missing API credentials."}
    store_constructor.assert_not_called()
    batch_runner.assert_not_called()


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


@pytest.mark.parametrize("method", ["get", "post"])
def test_job_routes_reject_invalid_job_ids_before_accessing_storage(client, monkeypatch, method):
    store_constructor = Mock(side_effect=AssertionError("An invalid job ID reached storage."))
    monkeypatch.setattr(job_routes, "MappingJobStore", store_constructor)
    batch_runner = Mock(side_effect=AssertionError("An invalid job ID started processing."))
    monkeypatch.setattr(job_routes, "run_batch", batch_runner)

    path = "/jobs/not-a-uuid/run" if method == "post" else "/jobs/not-a-uuid"
    response = client.request(method, path)

    assert response.status_code == 422
    assert response.json()["detail"][0]["loc"] == ["path", "job_id"]
    store_constructor.assert_not_called()
    batch_runner.assert_not_called()


@pytest.mark.parametrize("operation", ["create", "get", "run"])
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
    # Authentication still succeeds; only the job storage or processing operation fails.
    if operation == "run":
        monkeypatch.setattr(job_routes, "run_batch", Mock(side_effect=storage_error))
    else:
        monkeypatch.setattr(MappingJobStore, operation, Mock(side_effect=storage_error))

    if operation == "create":
        response = client.post("/jobs", json=job_payload)
    elif operation == "run":
        response = client.post(f"/jobs/{job_payload['job_id']}/run")
    else:
        response = client.get(f"/jobs/{job_payload['job_id']}")

    assert response.status_code == 503
    detail = "Job processing is unavailable." if operation == "run" else "Job storage is unavailable."
    assert response.json() == {"detail": detail}


@pytest.mark.parametrize("invalid_response", [False, True], ids=["mapped", "invalid-model-response"])
def test_run_resumes_batches_and_keeps_finished_jobs_unchanged(client, job_payload, model_calls, invalid_response):
    created = client.post("/jobs", json=job_payload).json()
    path = f"/jobs/{job_payload['job_id']}/run"
    if invalid_response:
        model_calls.side_effect = lambda prompt: "not JSON"

    # Six pairs need two requests with our test limit of four. Both return saved public progress.
    for count in (4, 6):
        response = client.post(path)
        expected_status = "in_progress"
        if count == 6:
            expected_status = "failed" if invalid_response else "completed"
        expected = {
            **created,
            "processed_pairs": 0 if invalid_response else count,
            "mapped_pairs": 0 if invalid_response else count,
            "errored_pairs": count if invalid_response else 0,
            "status": expected_status,
        }
        assert response.status_code == 200
        assert response.json() == expected
        assert client.get(f"/jobs/{job_payload['job_id']}").json() == expected
        assert model_calls.call_count == count

    # Completed and failed jobs both keep their outcomes without sending more model calls.
    repeated = client.post(path)
    assert repeated.status_code == 200
    assert repeated.json() == expected
    assert model_calls.call_count == 6


@pytest.mark.parametrize(
    ("state", "status_code", "detail"),
    [
        ("missing", 404, "Mapping job not found."),
        ("not_ready", 409, "Mapping job is not ready for processing."),
    ],
)
def test_run_rejects_unavailable_jobs_before_model_calls(
    client, table, job_payload, model_calls, state, status_code, detail
):
    if state == "not_ready":
        client.post("/jobs", json=job_payload)
        job = MappingJobStore(table).get(job_payload["job_id"])
        # Stand in for creation that hasn't finished saving all the pairs yet.
        table.put_item(Item={**job, "pairs_ready": False})

    response = client.post(f"/jobs/{job_payload['job_id']}/run")

    assert response.status_code == status_code
    assert response.json() == {"detail": detail}
    model_calls.assert_not_called()


def test_run_can_resume_saved_progress_after_a_model_request_times_out(client, job_payload, model_calls, monkeypatch):
    monkeypatch.setenv("LO_MAPPING_CONCURRENCY", "1")
    client.post("/jobs", json=job_payload)
    path = f"/jobs/{job_payload['job_id']}/run"
    original_reply = model_calls.side_effect

    def timeout_second_call(prompt):
        if model_calls.call_count == 2:
            raise ReadTimeoutError(endpoint_url="https://private-model-details.invalid")
        return original_reply(prompt)

    # The first answer is saved before the second request fails. The HTTP error must not reset it.
    model_calls.side_effect = timeout_second_call
    response = client.post(path)
    assert response.status_code == 503
    assert response.json() == {"detail": "Job processing is unavailable."}
    progress = client.get(f"/jobs/{job_payload['job_id']}").json()
    assert progress["processed_pairs"] == 1 and progress["errored_pairs"] == 0

    model_calls.side_effect = original_reply
    monkeypatch.setenv("LO_MAPPING_BATCH_SIZE", "6")
    resumed = client.post(path)
    assert resumed.status_code == 200
    assert resumed.json()["status"] == "completed"
    assert resumed.json()["processed_pairs"] == 6
    assert model_calls.call_count == 7


@pytest.mark.parametrize("setting", ["LO_MAPPING_BATCH_SIZE", "LO_MAPPING_BEDROCK_REGION"])
def test_run_returns_503_for_invalid_service_configuration(client, job_payload, model_calls, monkeypatch, setting):
    client.post("/jobs", json=job_payload)
    monkeypatch.setenv(setting, "")

    # These settings belong to the service, so this is a 503 rather than invalid caller input.
    response = client.post(f"/jobs/{job_payload['job_id']}/run")
    assert response.status_code == 503
    assert response.json() == {"detail": "Job processing is unavailable."}
    model_calls.assert_not_called()
    assert client.get(f"/jobs/{job_payload['job_id']}").json()["processed_pairs"] == 0
