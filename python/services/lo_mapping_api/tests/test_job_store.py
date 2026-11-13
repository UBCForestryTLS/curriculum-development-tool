from datetime import UTC, datetime
from unittest.mock import patch
from uuid import uuid4

import pytest
from botocore.exceptions import ClientError, EndpointConnectionError

from app.api_key_store import ApiKeyStore
from app.api_keys import generate_api_key
from app.job_models import MappingJobRequest
from app.job_store import MappingJobConflictError, MappingJobStore


@pytest.fixture
def store(table):
    return MappingJobStore(table)


def test_create_saves_job_metadata_and_initial_progress(store, table, job_request):
    started = datetime.now(UTC)
    record = store.create(job_request)
    job_id = str(job_request.job_id)
    expected = {
        "pk": f"JOB#{job_id}",
        "sk": "METADATA",
        "GSI1PK": "COURSE#4",
        "GSI1SK": f"JOB#{job_id}",
        "GSI2PK": "PROGRAM#9",
        "GSI2SK": f"JOB#{job_id}",
        "job_id": job_id,
        "course_id": 4,
        "program_id": 9,
        "total_clos": 2,
        "total_plos": 3,
        "total_pairs": 6,
        "processed_pairs": 0,
        "mapped_pairs": 0,
        "errored_pairs": 0,
        "status": "pending",
        "pairs_ready": True,
        "mapping_scale_options": [scale.model_dump() for scale in job_request.mapping_scales],
    }

    assert {field: record[field] for field in expected} == expected
    assert started <= datetime.fromisoformat(record["created_at"]) <= datetime.now(UTC)
    assert table.get_item(Key={"pk": record["pk"], "sk": "METADATA"})["Item"] == record


def test_create_saves_every_pair_with_original_text_and_empty_results(store, job_request):
    store.create(job_request)
    pairs = store.get_pairs(job_request.job_id)
    assert len(pairs) == 6
    assert {(pair["clo_id"], pair["plo_id"]) for pair in pairs} == {
        (21, 34), (21, 35), (21, 36), (22, 34), (22, 35), (22, 36),
    }
    clo_texts = {clo.l_outcome_id: clo.l_outcome for clo in job_request.course_learning_outcomes}
    plo_texts = {plo.pl_outcome_id: plo.pl_outcome for plo in job_request.program_learning_outcomes}
    for pair in pairs:
        assert pair == {
            "pk": f"JOB#{job_request.job_id}",
            "sk": f"LO_PAIR#{pair['clo_id']}-{pair['plo_id']}",
            "job_id": str(job_request.job_id),
            "clo_id": pair["clo_id"],
            "plo_id": pair["plo_id"],
            "clo_text": clo_texts[pair["clo_id"]],
            "plo_text": plo_texts[pair["plo_id"]],
            "processed": False,
            "error": None,
            "mapping_scales": [],
            "explanation": None,
        }


def test_reads_keep_jobs_and_api_keys_separate(store, table, job_request, job_payload):
    first = store.create(job_request)
    second_request = MappingJobRequest.model_validate({**job_payload, "job_id": str(uuid4())})
    second = store.create(second_request)
    credentials = generate_api_key()
    key_record = ApiKeyStore(table).create(credentials)

    assert store.get(str(job_request.job_id).upper()) == first
    assert store.get(second_request.job_id) == second
    for request in (job_request, second_request):
        pairs = store.get_pairs(request.job_id)
        assert len(pairs) == 6
        assert {pair["job_id"] for pair in pairs} == {str(request.job_id)}
    assert ApiKeyStore(table).get(credentials.key_id) == key_record
    missing_id = uuid4()
    assert store.get(missing_id) is None
    assert store.get_pairs(missing_id) == []


def test_repeated_creation_preserves_saved_progress_and_results(store, table, job_request):
    record = store.create(job_request)
    assert store.create(job_request) == record
    record.update(status="in_progress", processed_pairs=1, mapped_pairs=1)
    table.put_item(Item=record)
    pair = store.get_pairs(job_request.job_id)[0]
    pair.update(processed=True, mapping_scales=[3], explanation="Saved alignment.")
    table.put_item(Item=pair)
    saved_pairs = store.get_pairs(job_request.job_id)

    assert store.create(job_request) == record
    assert store.get_pairs(job_request.job_id) == saved_pairs


@pytest.mark.parametrize(
    ("collection", "field", "value"),
    [
        (None, "course_id", 99),
        (None, "program_id", 99),
        ("course_learning_outcomes", "l_outcome", "Changed CLO."),
        ("program_learning_outcomes", "pl_outcome", "Changed PLO."),
        ("mapping_scales", "description", "Changed level definition."),
    ],
)
def test_creation_rejects_changed_inputs_without_changing_saved_records(
    store, job_request, job_payload, collection, field, value
):
    record = store.create(job_request)
    pairs = store.get_pairs(job_request.job_id)
    target = job_payload if collection is None else job_payload[collection][0]
    target[field] = value

    with pytest.raises(MappingJobConflictError, match="different inputs"):
        store.create(MappingJobRequest.model_validate(job_payload))

    assert store.get(job_request.job_id) == record
    assert store.get_pairs(job_request.job_id) == pairs


def test_creation_resumes_after_a_pair_write_fails(store, table, job_request):
    original_put = table.put_item
    failure = EndpointConnectionError(endpoint_url="https://dynamodb.invalid")

    def interrupted_put(**kwargs):
        if kwargs["Item"]["sk"] == "LO_PAIR#21-35":
            raise failure
        return original_put(**kwargs)

    # Save the first pair, then lose the connection before the second one is saved.
    with patch.object(table, "put_item", side_effect=interrupted_put):
        with pytest.raises(EndpointConnectionError) as error:
            store.create(job_request)
    partial_job = store.get(job_request.job_id)
    assert error.value is failure
    assert partial_job["pairs_ready"] is False
    assert len(store.get_pairs(job_request.job_id)) == 1

    resumed_job = store.create(job_request)
    assert resumed_job["pairs_ready"] is True
    assert resumed_job["created_at"] == partial_job["created_at"]
    assert len(store.get_pairs(job_request.job_id)) == 6


def test_creation_preserves_results_saved_by_an_overlapping_attempt(store, table, job_request):
    original_put = table.put_item
    other_attempt_finished = False
    saved_pair = None

    def overlapping_put(**kwargs):
        nonlocal other_attempt_finished, saved_pair
        if kwargs["Item"]["sk"] == "LO_PAIR#21-34" and not other_attempt_finished:
            other_attempt_finished = True
            # Another attempt finishes creation and processing starts before this write resumes.
            job = store.create(job_request)
            job.update(status="in_progress", processed_pairs=1, mapped_pairs=1)
            original_put(Item=job)
            saved_pair = store.get_pairs(job_request.job_id)[0]
            saved_pair.update(processed=True, mapping_scales=[3], explanation="Keep this result.")
            original_put(Item=saved_pair)
        return original_put(**kwargs)

    with patch.object(table, "put_item", side_effect=overlapping_put):
        record = store.create(job_request)

    assert record["status"] == "in_progress"
    assert record["processed_pairs"] == record["mapped_pairs"] == 1
    pairs = store.get_pairs(job_request.job_id)
    assert len(pairs) == 6
    assert saved_pair in pairs


def test_creation_can_retry_when_the_final_readiness_update_fails(store, table, job_request):
    failure = EndpointConnectionError(endpoint_url="https://dynamodb.invalid")
    with patch.object(table, "update_item", side_effect=failure):
        with pytest.raises(EndpointConnectionError):
            store.create(job_request)

    assert store.get(job_request.job_id)["pairs_ready"] is False
    saved_pairs = store.get_pairs(job_request.job_id)
    assert len(saved_pairs) == 6
    assert store.create(job_request)["pairs_ready"] is True
    assert store.get_pairs(job_request.job_id) == saved_pairs


def test_get_pairs_reads_every_page(store, table, job_request):
    store.create(job_request)
    expected_pairs = store.get_pairs(job_request.job_id)
    original_query = table.meta.client.query

    def limited_query(**kwargs):
        # Small pages exercise pagination without needing a large test job.
        return original_query(**{**kwargs, "Limit": 2})

    with patch.object(table.meta.client, "query", side_effect=limited_query) as query:
        assert store.get_pairs(job_request.job_id) == expected_pairs
    assert query.call_count > 1
    assert all("ExclusiveStartKey" in call.kwargs for call in query.call_args_list[1:])
    assert all(call.kwargs["ConsistentRead"] is True for call in query.call_args_list)


@pytest.mark.parametrize("operation", ["create", "get", "get_pairs"])
def test_missing_table_errors_reach_the_caller(store, table, job_request, operation):
    table.delete()
    argument = job_request if operation == "create" else job_request.job_id

    with pytest.raises(ClientError) as error:
        getattr(store, operation)(argument)

    assert error.value.response["Error"]["Code"] == "ResourceNotFoundException"
