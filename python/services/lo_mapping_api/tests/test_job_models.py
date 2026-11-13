import json
from uuid import UUID

import pytest
from pydantic import ValidationError

from app.job_models import MappingJobRequest


def test_job_request_accepts_json_and_preserves_supplied_text(job_payload):
    request = MappingJobRequest.model_validate_json(json.dumps(job_payload))

    assert request.job_id == UUID(job_payload["job_id"])
    assert request.model_dump(mode="json") == job_payload


@pytest.mark.parametrize(
    ("collection", "field"),
    [
        (None, "job_id"),
        (None, "course_id"),
        (None, "program_id"),
        (None, "course_learning_outcomes"),
        (None, "program_learning_outcomes"),
        (None, "mapping_scales"),
        ("course_learning_outcomes", "l_outcome"),
        ("program_learning_outcomes", "pl_outcome"),
        ("mapping_scales", "abbreviation"),
    ],
)
def test_job_request_requires_job_and_nested_fields(job_payload, collection, field):
    target = job_payload if collection is None else job_payload[collection][0]
    del target[field]

    with pytest.raises(ValidationError) as error:
        MappingJobRequest.model_validate(job_payload)

    assert error.value.errors()[0]["type"] == "missing"


def test_job_request_requires_a_valid_uuid(job_payload):
    job_payload["job_id"] = "not-a-uuid"

    with pytest.raises(ValidationError, match="job_id"):
        MappingJobRequest.model_validate(job_payload)


@pytest.mark.parametrize(
    ("collection", "field", "value"),
    [
        # These fields share the same ID rule, so spread invalid values across them.
        (None, "course_id", 0),
        (None, "course_id", -1),
        (None, "program_id", True),
        ("course_learning_outcomes", "l_outcome_id", "21"),
        ("program_learning_outcomes", "pl_outcome_id", 1.5),
        ("mapping_scales", "map_scale_id", None),
    ],
)
def test_job_request_requires_positive_integer_ids(job_payload, collection, field, value):
    target = job_payload if collection is None else job_payload[collection][0]
    target[field] = value

    with pytest.raises(ValidationError, match=field):
        MappingJobRequest.model_validate(job_payload)


@pytest.mark.parametrize(
    ("collection", "field", "value"),
    [
        ("course_learning_outcomes", "l_outcome", ""),
        ("program_learning_outcomes", "pl_outcome", " \t\n"),
        ("mapping_scales", "title", 7),
        ("mapping_scales", "abbreviation", " "),
        ("mapping_scales", "description", None),
    ],
)
def test_job_request_requires_nonblank_text(job_payload, collection, field, value):
    job_payload[collection][0][field] = value

    with pytest.raises(ValidationError, match=field):
        MappingJobRequest.model_validate(job_payload)


@pytest.mark.parametrize(
    "collection", ["course_learning_outcomes", "program_learning_outcomes", "mapping_scales"]
)
def test_job_request_requires_nonempty_lists(job_payload, collection):
    job_payload[collection] = []

    with pytest.raises(ValidationError, match=collection):
        MappingJobRequest.model_validate(job_payload)


@pytest.mark.parametrize(
    ("collection", "field", "message"),
    [
        ("course_learning_outcomes", "l_outcome_id", "CLO IDs must be unique"),
        ("program_learning_outcomes", "pl_outcome_id", "PLO IDs must be unique"),
        ("mapping_scales", "map_scale_id", "Mapping scale IDs must be unique"),
        ("mapping_scales", "abbreviation", "Mapping scale abbreviations must be unique"),
    ],
)
def test_job_request_rejects_duplicate_ids_and_labels(job_payload, collection, field, message):
    job_payload[collection][1][field] = job_payload[collection][0][field]

    with pytest.raises(ValidationError, match=message):
        MappingJobRequest.model_validate(job_payload)


def test_job_request_rejects_caller_supplied_status(job_payload):
    job_payload["status"] = "completed"

    with pytest.raises(ValidationError) as error:
        MappingJobRequest.model_validate(job_payload)

    assert error.value.errors()[0]["type"] == "extra_forbidden"
