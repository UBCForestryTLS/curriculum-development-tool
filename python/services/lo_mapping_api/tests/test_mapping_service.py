import json
from unittest.mock import Mock

import pytest
from botocore.exceptions import ClientError, ReadTimeoutError

from app.bedrock_client import BedrockClient, BedrockResponseError
from app.mapping_prompt import build_mapping_prompt
from app.mapping_response import MappingResponseError
from app.mapping_service import map_pair


@pytest.fixture
def pair_inputs():
    return {
        "clo_id": 21,
        "clo_text": "Explain the main processes in a forest ecosystem.",
        "plo_id": 34,
        "plo_text": "Apply ecological principles to forest management.",
        "mapping_scales": [
            {
                "map_scale_id": 3,
                "title": "Foundation",
                "abbreviation": "FOUND",
                "description": "Explain the foundational concepts.",
            },
            {
                "map_scale_id": 8,
                "title": "Application",
                "abbreviation": "APPLY",
                "description": "Apply concepts in a new setting.",
            },
        ],
    }


@pytest.fixture
def model_response():
    return {
        "id": "clo-21__plo-34",
        "is_mapped": True,
        "mapLabels": ["FOUND"],
        "explanation": "Example alignment decision.",
    }


@pytest.fixture
def bedrock(monkeypatch):
    # Keep the real prompt and parser, but never create a real AWS client here.
    client = Mock(spec_set=BedrockClient)
    monkeypatch.setattr(
        "app.mapping_service.BedrockClient",
        Mock(side_effect=AssertionError("Reuse the supplied client instead of creating one.")),
    )
    return client


@pytest.mark.parametrize(
    ("is_mapped", "labels"),
    [(True, ["FOUND"]), (True, ["APPLY", "FOUND"]), (False, [])],
    ids=["single-level", "multiple-levels", "unmapped"],
)
def test_map_pair_sends_the_prompt_once_and_returns_the_validated_result(
    pair_inputs, model_response, bedrock, is_mapped, labels
):
    model_response.update(is_mapped=is_mapped, mapLabels=labels)
    bedrock.generate_text.return_value = json.dumps(model_response)

    result = map_pair(**pair_inputs, bedrock_client=bedrock)

    bedrock.generate_text.assert_called_once_with(build_mapping_prompt(**pair_inputs))
    assert result == {
        "id": "clo-21__plo-34",
        "is_mapped": is_mapped,
        "map_labels": labels,
        "explanation": "Example alignment decision.",
    }


@pytest.mark.parametrize(
    ("field", "value", "message"),
    [
        ("clo_text", " ", "CLO and PLO text.*blank"),
        ("plo_text", "", "CLO and PLO text.*blank"),
        ("mapping_scales", [], "At least one mapping scale"),
    ],
)
def test_map_pair_rejects_invalid_inputs_before_calling_bedrock(
    pair_inputs, bedrock, field, value, message
):
    pair_inputs[field] = value

    with pytest.raises(ValueError, match=message):
        map_pair(**pair_inputs, bedrock_client=bedrock)

    bedrock.generate_text.assert_not_called()


def test_map_pair_rejects_malformed_model_output(pair_inputs, bedrock):
    bedrock.generate_text.return_value = "Not a JSON response."

    with pytest.raises(MappingResponseError, match="must be valid JSON"):
        map_pair(**pair_inputs, bedrock_client=bedrock)

    bedrock.generate_text.assert_called_once()


@pytest.mark.parametrize(
    ("field", "value", "message"),
    [
        ("id", "clo-21__plo-99", "does not match the requested pair"),
        ("mapLabels", ["UNKNOWN"], "unknown mapping label"),
    ],
)
def test_map_pair_checks_the_returned_pair_and_mapping_levels(
    pair_inputs, model_response, bedrock, field, value, message
):
    model_response[field] = value
    bedrock.generate_text.return_value = json.dumps(model_response)

    with pytest.raises(MappingResponseError, match=message):
        map_pair(**pair_inputs, bedrock_client=bedrock)

    bedrock.generate_text.assert_called_once()


@pytest.mark.parametrize(
    "client_error",
    [
        BedrockResponseError("Bedrock returned an incomplete answer."),
        ClientError({"Error": {"Code": "ThrottlingException"}}, "Converse"),
        ReadTimeoutError(endpoint_url="https://bedrock.invalid"),
    ],
    ids=["incomplete-answer", "aws-error", "timeout"],
)
def test_map_pair_preserves_client_errors_without_retrying(pair_inputs, bedrock, client_error):
    bedrock.generate_text.side_effect = client_error

    # A failed request must reach the caller as an error, not an unmapped result.
    with pytest.raises(type(client_error)) as error:
        map_pair(**pair_inputs, bedrock_client=bedrock)

    assert error.value is client_error
    bedrock.generate_text.assert_called_once()
