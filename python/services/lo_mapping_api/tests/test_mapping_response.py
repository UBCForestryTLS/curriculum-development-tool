import json

import pytest

from app.mapping_response import MappingResponseError, parse_mapping_response


@pytest.fixture
def response_context():
    return {
        "expected_pair_id": "clo-7__plo-12",
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
def valid_response():
    return {
        "id": "clo-7__plo-12",
        "is_mapped": True,
        "mapLabels": ["FOUND"],
        "explanation": "The CLO supports the PLO's ecological principles.",
    }


@pytest.mark.parametrize(
    ("pair_id", "is_mapped", "labels"),
    [
        ("clo-7__plo-12", True, ["FOUND"]),
        ("clo-21__plo-34", True, ["APPLY", "FOUND"]),
        ("clo-7__plo-12", False, []),
    ],
    ids=["single-level", "multiple-levels", "unmapped"],
)
def test_parser_returns_valid_mapping_results(
    response_context, valid_response, pair_id, is_mapped, labels
):
    response_context["expected_pair_id"] = pair_id
    valid_response.update(id=pair_id, is_mapped=is_mapped, mapLabels=labels)
    if not is_mapped:
        # No alignment is a valid result, not a processing error.
        valid_response["explanation"] = "The CLO concerns a different subject from the PLO."

    result = parse_mapping_response(json.dumps(valid_response), **response_context)

    assert result == {
        "id": pair_id,
        "is_mapped": is_mapped,
        "map_labels": labels,
        "explanation": valid_response["explanation"],
    }


def test_parser_accepts_json_whitespace_and_preserves_explanation_text(response_context, valid_response):
    explanation = '  Explain "forest health".\nExamples from forêt → application.  '
    valid_response["explanation"] = explanation
    response_text = " \n" + json.dumps(valid_response, ensure_ascii=False) + "\t\n"

    result = parse_mapping_response(response_text, **response_context)

    assert result["explanation"] == explanation


@pytest.mark.parametrize("response_text", [None, {}, 1, b"{}"])
def test_parser_rejects_non_text_responses(response_context, response_text):
    with pytest.raises(MappingResponseError, match="must be JSON text"):
        parse_mapping_response(response_text, **response_context)


@pytest.mark.parametrize("response_text", ["", " \t\n", '{"id":', '{"id": "value",}'])
def test_parser_rejects_malformed_json(response_context, response_text):
    with pytest.raises(MappingResponseError, match="must be valid JSON"):
        parse_mapping_response(response_text, **response_context)


@pytest.mark.parametrize(
    "response_format",
    [
        "```json\n{response}\n```",
        "Here is the result: {response}",
        "{response} extra text",
        "{response}{response}",
    ],
)
def test_parser_requires_one_json_result_without_wrapping_or_extra_text(
    response_context, valid_response, response_format
):
    response_text = response_format.format(response=json.dumps(valid_response))

    with pytest.raises(MappingResponseError, match="must be valid JSON"):
        parse_mapping_response(response_text, **response_context)


@pytest.mark.parametrize("response_text", ["[]", "null", "true", "42", '"result"'])
def test_parser_rejects_json_values_that_are_not_objects(response_context, response_text):
    with pytest.raises(MappingResponseError, match="must be a JSON object"):
        parse_mapping_response(response_text, **response_context)


def test_parser_rejects_repeated_json_fields(response_context, valid_response):
    # A later ID must not overwrite an earlier one and make the response look valid.
    response_text = '{"id": "clo-99__plo-99", ' + json.dumps(valid_response)[1:]

    with pytest.raises(MappingResponseError, match="must not repeat JSON fields"):
        parse_mapping_response(response_text, **response_context)


@pytest.mark.parametrize("field", ["id", "is_mapped", "mapLabels", "explanation"])
def test_parser_rejects_missing_fields(response_context, valid_response, field):
    valid_response.pop(field)

    with pytest.raises(MappingResponseError, match="must contain exactly"):
        parse_mapping_response(json.dumps(valid_response), **response_context)


def test_parser_rejects_unexpected_fields(response_context, valid_response):
    valid_response["extra"] = "Unexpected model output."

    with pytest.raises(MappingResponseError, match="must contain exactly"):
        parse_mapping_response(json.dumps(valid_response), **response_context)


@pytest.mark.parametrize(
    "pair_id",
    [None, 7, "", "clo-7__plo-99", "clo-99__plo-12", " clo-7__plo-12 "],
)
def test_parser_rejects_invalid_or_mismatched_pair_ids(response_context, valid_response, pair_id):
    valid_response["id"] = pair_id

    with pytest.raises(MappingResponseError, match="does not match the requested pair"):
        parse_mapping_response(json.dumps(valid_response), **response_context)


@pytest.mark.parametrize("is_mapped", [None, "true", "false", 0, 1, [], {}])
def test_parser_rejects_alignment_decisions_that_are_not_booleans(
    response_context, valid_response, is_mapped
):
    valid_response["is_mapped"] = is_mapped

    with pytest.raises(MappingResponseError, match="is_mapped must be a boolean"):
        parse_mapping_response(json.dumps(valid_response), **response_context)


@pytest.mark.parametrize(
    "labels",
    [None, "FOUND", {}, [3], [None], [True], [["FOUND"]], ["FOUND", {"label": "APPLY"}]],
)
def test_parser_rejects_labels_that_are_not_an_array_of_strings(response_context, valid_response, labels):
    valid_response["mapLabels"] = labels

    with pytest.raises(MappingResponseError, match="mapLabels must be an array of strings"):
        parse_mapping_response(json.dumps(valid_response), **response_context)


@pytest.mark.parametrize(
    ("label", "message"),
    [
        ("FOUND", "duplicate mapping labels"),
        ("UNKNOWN", "unknown mapping label"),
        ("found", "unknown mapping label"),
        (" FOUND ", "unknown mapping label"),
        ("", "unknown mapping label"),
    ],
)
def test_parser_rejects_duplicate_or_unknown_labels(response_context, valid_response, label, message):
    valid_response["mapLabels"] = ["FOUND", label]

    with pytest.raises(MappingResponseError, match=message):
        parse_mapping_response(json.dumps(valid_response), **response_context)


def test_parser_only_accepts_levels_available_in_the_current_request(response_context, valid_response):
    response_context["mapping_scales"] = response_context["mapping_scales"][:1]
    valid_response["mapLabels"] = ["APPLY"]

    with pytest.raises(MappingResponseError, match="unknown mapping label"):
        parse_mapping_response(json.dumps(valid_response), **response_context)


@pytest.mark.parametrize(
    ("is_mapped", "labels", "message"),
    [
        (True, [], "An aligned pair must have at least one mapping label"),
        (False, ["FOUND"], "An unaligned pair must have no mapping labels"),
    ],
)
def test_parser_rejects_labels_that_contradict_the_alignment_decision(
    response_context, valid_response, is_mapped, labels, message
):
    valid_response.update(is_mapped=is_mapped, mapLabels=labels)

    with pytest.raises(MappingResponseError, match=message):
        parse_mapping_response(json.dumps(valid_response), **response_context)


@pytest.mark.parametrize("explanation", [None, "", " \t\n", 3, False, [], {}])
def test_parser_rejects_blank_or_non_text_explanations(response_context, valid_response, explanation):
    valid_response["explanation"] = explanation

    with pytest.raises(MappingResponseError, match="explanation must be a nonblank string"):
        parse_mapping_response(json.dumps(valid_response), **response_context)
