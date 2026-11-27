import base64
import json
from datetime import UTC, datetime
from unittest.mock import Mock
from uuid import uuid4

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
        "retry_of_job_id": None,
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


@pytest.mark.parametrize("method", ["post", "get", "run", "results", "retry"])
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
    elif method == "retry":
        response = client.post(f"/jobs/{job_payload['job_id']}/retry", json={"job_id": str(uuid4())})
    elif method == "run":
        response = client.post(f"/jobs/{job_payload['job_id']}/run")
    elif method == "results":
        response = client.get(f"/jobs/{job_payload['job_id']}/results")
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


@pytest.mark.parametrize(
    ("method", "suffix"), [("get", ""), ("post", "/run"), ("get", "/results"), ("post", "/retry")],
)
def test_job_routes_reject_invalid_job_ids_before_accessing_storage(client, monkeypatch, method, suffix):
    store_constructor = Mock(side_effect=AssertionError("An invalid job ID reached storage."))
    monkeypatch.setattr(job_routes, "MappingJobStore", store_constructor)
    batch_runner = Mock(side_effect=AssertionError("An invalid job ID started processing."))
    monkeypatch.setattr(job_routes, "run_batch", batch_runner)

    body = {"job_id": str(uuid4())} if suffix == "/retry" else None
    response = client.request(method, f"/jobs/not-a-uuid{suffix}", json=body)

    assert response.status_code == 422
    assert response.json()["detail"][0]["loc"] == ["path", "job_id"]
    store_constructor.assert_not_called()
    batch_runner.assert_not_called()


@pytest.mark.parametrize("operation", ["create", "get", "run", "get_results_page", "create_retry"])
@pytest.mark.parametrize(
    ("storage_error", "action"),
    [
        (
            ClientError(
                {"Error": {"Code": "AccessDeniedException", "Message": "private-storage-details"}},
                "GetItem",
            ),
            "stop",
        ),
        (EndpointConnectionError(endpoint_url="https://private-storage-details.invalid"), "retry"),
        (
            ClientError({
                "Error": {"Code": "ProvisionedThroughputExceededException", "Message": "private-storage-details"},
                "ResponseMetadata": {"HTTPStatusCode": 400},
            }, "GetItem"),
            "retry",
        ),
    ],
    ids=["aws-error", "connection-error", "throttling"],
)
def test_storage_failures_return_503_without_exposing_details(
    client, job_payload, monkeypatch, operation, storage_error, action
):
    # Authentication still succeeds; only the job storage or processing operation fails.
    if operation == "run":
        monkeypatch.setattr(job_routes, "run_batch", Mock(side_effect=storage_error))
    else:
        if operation == "get_results_page":
            client.post("/jobs", json=job_payload)
        monkeypatch.setattr(MappingJobStore, operation, Mock(side_effect=storage_error))

    if operation == "create":
        response = client.post("/jobs", json=job_payload)
    elif operation == "create_retry":
        response = client.post(f"/jobs/{job_payload['job_id']}/retry", json={"job_id": str(uuid4())})
    elif operation == "run":
        response = client.post(f"/jobs/{job_payload['job_id']}/run")
    elif operation == "get_results_page":
        response = client.get(f"/jobs/{job_payload['job_id']}/results")
    else:
        response = client.get(f"/jobs/{job_payload['job_id']}")

    assert response.status_code == 503
    detail = "Job processing is unavailable." if operation == "run" else "Job storage is unavailable."
    assert response.json() == {"detail": {"message": detail, "action": action}}


@pytest.mark.parametrize(
    ("state", "status_code"),
    [("failed", 200), ("missing", 404), ("pending", 409), ("completed", 409), ("same-id", 409), ("used-id", 409)],
)
def test_retry_route_requires_a_finished_job_with_failures_and_a_new_id(
    client, table, job_request, state, status_code
):
    store = MappingJobStore(table)
    source_id, retry_id = str(job_request.job_id), str(uuid4())
    if state != "missing":
        store.create(job_request)
        if state != "pending":
            for pair in store.get_pairs(source_id):
                ids = (pair["clo_id"], pair["plo_id"])
                if ids == (21, 34) and state != "completed":
                    store.save_pair_error(source_id, *ids, error="Invalid response.")
                else:
                    store.save_pair_result(source_id, *ids, mapping_scale_ids=[], explanation="No match.")
            store.finish_if_complete(source_id)
    if state == "same-id":
        retry_id = source_id
    elif state == "used-id":
        # Use an existing normal job ID to ensure retry creation cannot replace it.
        existing = store.create(job_request.model_copy(update={"job_id": uuid4()}))
        retry_id = existing["job_id"]
    before = table.scan()["Items"]

    response = client.post(f"/jobs/{source_id}/retry", json={"job_id": retry_id})

    assert response.status_code == status_code
    if status_code == 200:
        assert response.json()["retry_of_job_id"] == source_id
        assert response.json()["total_pairs"] == 1
        assert client.get(f"/jobs/{retry_id}").json() == response.json()
    else:
        # Rejected requests must leave both existing jobs and pair outcomes alone.
        assert table.scan()["Items"] == before


@pytest.mark.parametrize("body", [{}, {"job_id": "not-a-uuid"}, {"job_id": str(uuid4()), "status": "completed"}])
def test_retry_rejects_invalid_inputs_before_accessing_storage(client, job_payload, monkeypatch, body):
    constructor = Mock(side_effect=AssertionError("Invalid retry inputs reached storage."))
    monkeypatch.setattr(job_routes, "MappingJobStore", constructor)

    response = client.post(f"/jobs/{job_payload['job_id']}/retry", json=body)

    assert response.status_code == 422
    constructor.assert_not_called()


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
        # Results are readable between batches and after completion, including saved errors.
        results = client.get(f"/jobs/{job_payload['job_id']}/results")
        assert results.status_code == 200
        page = results.json()
        assert page["status"] == expected_status
        assert len(page["results"]) == count
        assert page["next_cursor"] is None
        assert all(pair["processed"] is not invalid_response for pair in page["results"])
        assert all((pair["error"] is not None) == invalid_response for pair in page["results"])
        assert model_calls.call_count == count

    # Completed and failed jobs both keep their outcomes without sending more model calls.
    repeated = client.post(path)
    assert repeated.status_code == 200
    assert repeated.json() == expected
    assert model_calls.call_count == 6


def test_locked_job_rejects_another_batch_but_still_allows_reads(client, table, job_payload, model_calls):
    job_id = job_payload["job_id"]
    client.post("/jobs", json=job_payload)
    store = MappingJobStore(table)
    store.save_pair_result(job_id, 21, 34, mapping_scale_ids=[3], explanation="Already saved.")
    progress = client.get(f"/jobs/{job_id}").json()
    results = client.get(f"/jobs/{job_id}/results").json()
    token = store.acquire_batch_lock(job_id)

    # Stand in for an active batch while another processing request reaches the API.
    response = client.post(f"/jobs/{job_id}/run")
    assert response.status_code == 409
    assert response.json()["detail"]["action"] == "wait"
    model_calls.assert_not_called()
    assert store.get(job_id)["batch_lock_token"] == token

    # Reads stay available and must not expose the internal lock fields.
    assert client.get(f"/jobs/{job_id}").json() == progress
    assert client.get(f"/jobs/{job_id}/results").json() == results

    assert store.release_batch_lock(job_id, token)
    resumed = client.post(f"/jobs/{job_id}/run")
    assert resumed.status_code == 200
    assert resumed.json()["processed_pairs"] == 5
    assert model_calls.call_count == 4


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


@pytest.mark.parametrize(
    ("error_code", "http_status", "action"),
    [
        ("timeout", None, "retry"),
        ("ThrottlingException", 429, "retry"),
        ("UpstreamFailure", 503, "retry"),
        ("AccessDeniedException", 403, "stop"),
        ("UnknownFailure", 400, "stop"),
    ],
)
def test_run_preserves_results_and_can_resume_after_a_service_failure(
    client, job_payload, model_calls, monkeypatch, error_code, http_status, action
):
    monkeypatch.setenv("LO_MAPPING_CONCURRENCY", "1")
    client.post("/jobs", json=job_payload)
    path = f"/jobs/{job_payload['job_id']}/run"
    original_reply = model_calls.side_effect

    def fail_second_call(prompt):
        if model_calls.call_count == 2:
            if error_code == "timeout":
                raise ReadTimeoutError(endpoint_url="https://private-model-details.invalid")
            raise ClientError({
                "Error": {"Code": error_code, "Message": "private-model-details"},
                "ResponseMetadata": {"HTTPStatusCode": http_status},
            }, "Converse")
        return original_reply(prompt)

    # The first answer is saved before the second request fails. The HTTP error must not reset it.
    model_calls.side_effect = fail_second_call
    response = client.post(path)
    assert response.status_code == 503
    assert response.json() == {"detail": {"message": "Job processing is unavailable.", "action": action}}
    assert model_calls.call_count == 2
    progress = client.get(f"/jobs/{job_payload['job_id']}").json()
    assert progress["processed_pairs"] == 1 and progress["errored_pairs"] == 0
    saved_results = client.get(f"/jobs/{job_payload['job_id']}/results").json()["results"]
    assert len(saved_results) == 1

    # For stop errors, this stands in for fixing the underlying issue before requesting a resume.
    model_calls.side_effect = original_reply
    monkeypatch.setenv("LO_MAPPING_BATCH_SIZE", "6")
    resumed = client.post(path)
    assert resumed.status_code == 200
    assert resumed.json()["status"] == "completed"
    assert resumed.json()["processed_pairs"] == 6
    assert model_calls.call_count == 7
    assert client.get(f"/jobs/{job_payload['job_id']}/results").json()["results"][0] == saved_results[0]


@pytest.mark.parametrize(
    ("reasons", "action"),
    [
        (["None", "TransactionConflict"], "retry"),
        (["ThrottlingError", "ProvisionedThroughputExceeded"], "retry"),
        (["ConditionalCheckFailed", "TransactionConflict"], "stop"),
        (["ValidationError", "None"], "stop"),
        ([], "stop"),
    ],
)
def test_cancelled_saves_return_the_right_action_without_resetting_progress(
    client, table, job_request, model_calls, monkeypatch, reasons, action
):
    store = MappingJobStore(table)
    store.create(job_request)
    store.save_pair_result(job_request.job_id, 21, 34, mapping_scale_ids=[3], explanation="Already saved.")
    saved_job, saved_pairs = store.get(job_request.job_id), store.get_pairs(job_request.job_id)
    monkeypatch.setenv("LO_MAPPING_CONCURRENCY", "1")
    # Cancel the next save. A failed condition must not be treated like temporary throttling.
    failure = ClientError({
        "Error": {"Code": "TransactionCanceledException", "Message": "private-storage-details"},
        "CancellationReasons": [{"Code": reason} for reason in reasons],
    }, "TransactWriteItems")
    monkeypatch.setattr(MappingJobStore, "save_pair_result", Mock(side_effect=failure))

    response = client.post(f"/jobs/{job_request.job_id}/run")

    assert response.status_code == 503
    assert response.json() == {"detail": {"message": "Job processing is unavailable.", "action": action}}
    assert model_calls.call_count == 1
    assert store.get(job_request.job_id) == saved_job
    assert store.get_pairs(job_request.job_id) == saved_pairs


@pytest.mark.parametrize("setting", ["LO_MAPPING_BATCH_SIZE", "LO_MAPPING_BEDROCK_REGION"])
def test_run_returns_503_for_invalid_service_configuration(client, job_payload, model_calls, monkeypatch, setting):
    client.post("/jobs", json=job_payload)
    monkeypatch.setenv(setting, "")

    # These settings belong to the service, so this is a 503 rather than invalid caller input.
    response = client.post(f"/jobs/{job_payload['job_id']}/run")
    assert response.status_code == 503
    assert response.json() == {"detail": {"message": "Job processing is unavailable.", "action": "stop"}}
    model_calls.assert_not_called()
    assert client.get(f"/jobs/{job_payload['job_id']}").json()["processed_pairs"] == 0


def test_results_return_only_saved_outcomes_for_the_requested_job(client, table, job_request):
    store = MappingJobStore(table)
    job_id = job_request.job_id
    store.create(job_request)
    path = f"/jobs/{job_id}/results"
    progress = client.get(f"/jobs/{job_id}").json()
    # No results yet does not mean the job is finished.
    assert client.get(path).json() == {**progress, "results": [], "next_cursor": None}

    store.save_pair_result(job_id, 21, 34, mapping_scale_ids=[3, 8], explanation="Both levels apply.")
    store.save_pair_result(job_id, 21, 35, mapping_scale_ids=[], explanation="No match.")
    store.save_pair_error(job_id, 21, 36, error="Invalid model response.")
    other_id = uuid4()
    store.create(job_request.model_copy(update={"job_id": other_id}))
    store.save_pair_result(other_id, 21, 34, mapping_scale_ids=[8], explanation="Other job.")
    saved_pairs = store.get_pairs(job_id)
    progress = client.get(f"/jobs/{job_id}").json()

    response = client.get(path)

    assert response.status_code == 200
    # Check public fields too, so original text and internal storage fields stay out of the API.
    assert response.json() == {
        **progress,
        "results": [
            {
                "clo_id": 21, "plo_id": 34, "processed": True,
                "mapping_scales": [3, 8], "explanation": "Both levels apply.", "error": None,
            },
            {
                "clo_id": 21, "plo_id": 35, "processed": True,
                "mapping_scales": [], "explanation": "No match.", "error": None,
            },
            {
                "clo_id": 21, "plo_id": 36, "processed": False,
                "mapping_scales": [], "explanation": None, "error": "Invalid model response.",
            },
        ],
        "next_cursor": None,
    }
    assert store.get_pairs(job_id) == saved_pairs
    assert client.get(f"/jobs/{job_id}").json() == progress


def test_results_follow_empty_pages_and_pick_up_later_outcomes_on_refresh(client, table, job_request, monkeypatch):
    store = MappingJobStore(table)
    job_id = job_request.job_id
    store.create(job_request)
    store.save_pair_result(job_id, 22, 36, mapping_scale_ids=[8], explanation="Last pair.")
    monkeypatch.setattr(job_routes, "MappingJobStore", lambda: store)
    query = Mock(wraps=table.query)
    monkeypatch.setattr(table, "query", query)
    path = f"/jobs/{job_id}/results"

    page = client.get(path, params={"limit": 2}).json()
    assert page["results"] == [] and page["next_cursor"] is not None
    # This earlier pair finishes after the cursor has passed it.
    store.save_pair_result(job_id, 21, 34, mapping_scale_ids=[], explanation="Finished later.")
    results = []
    page_requests = 1
    # Even the last full page can have a cursor. Follow it until DynamoDB says we're done.
    while page["next_cursor"] is not None:
        assert page_requests < 5
        response = client.get(path, params={"limit": 2, "cursor": page["next_cursor"]})
        assert response.status_code == 200
        page = response.json()
        results.extend(page["results"])
        page_requests += 1
    assert [(pair["clo_id"], pair["plo_id"]) for pair in results] == [(22, 36)]
    assert query.call_count == page_requests  # One bounded storage read per API page, even when empty.
    assert all(call.kwargs["Limit"] == 2 and call.kwargs["ConsistentRead"] for call in query.call_args_list)

    # Laravel's next refresh starts from the beginning, so the earlier result isn't lost.
    refreshed = client.get(path).json()
    assert [(pair["clo_id"], pair["plo_id"]) for pair in refreshed["results"]] == [(21, 34), (22, 36)]
    assert refreshed["status"] == "in_progress"


@pytest.mark.parametrize("state", ["missing", "not_ready"])
def test_results_reject_unavailable_jobs_without_reading_pairs(client, table, job_request, monkeypatch, state):
    if state == "not_ready":
        job = MappingJobStore(table).create(job_request)
        table.put_item(Item={**job, "pairs_ready": False})
    read_page = Mock(side_effect=AssertionError("Unavailable job reached pair storage."))
    monkeypatch.setattr(MappingJobStore, "get_results_page", read_page)

    response = client.get(f"/jobs/{job_request.job_id}/results")

    assert response.status_code == (404 if state == "missing" else 409)
    read_page.assert_not_called()


@pytest.mark.parametrize("params", [{"limit": 0}, {"limit": 101}, {"limit": "invalid"}, {"cursor": ""}])
def test_results_reject_invalid_query_parameters(client, job_request, monkeypatch, params):
    store = Mock(side_effect=AssertionError("Invalid query reached job storage."))
    monkeypatch.setattr(job_routes, "MappingJobStore", store)

    response = client.get(f"/jobs/{job_request.job_id}/results", params=params)

    assert response.status_code == 422
    store.assert_not_called()


@pytest.mark.parametrize("cursor_case", ["not-base64", "not-json", "wrong-shape", "metadata-key", "other-job"])
def test_results_reject_invalid_or_cross_job_cursors(client, table, job_request, monkeypatch, cursor_case):
    store = MappingJobStore(table)
    job = store.create(job_request)
    # A cursor can only point to a pair within the job being requested.
    cursors = {
        "not-base64": "invalid!",
        "not-json": base64.urlsafe_b64encode(b"not JSON").decode(),
        "wrong-shape": base64.urlsafe_b64encode(b"[]").decode(),
        "metadata-key": base64.urlsafe_b64encode(json.dumps(store._key(job_request.job_id)).encode()).decode(),
        "other-job": base64.urlsafe_b64encode(json.dumps({
            "pk": f"JOB#{uuid4()}", "sk": "LO_PAIR#21-34",
        }).encode()).decode(),
    }
    monkeypatch.setattr(job_routes, "MappingJobStore", lambda: store)
    query = Mock(side_effect=AssertionError("Invalid cursor reached the pair query."))
    monkeypatch.setattr(table, "query", query)

    response = client.get(f"/jobs/{job['job_id']}/results", params={"cursor": cursors[cursor_case]})

    assert response.status_code == 422
    assert response.json() == {"detail": "Invalid results cursor for this job."}
    query.assert_not_called()
