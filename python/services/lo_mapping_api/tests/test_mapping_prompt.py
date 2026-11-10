import json

import pytest

from app.mapping_prompt import build_mapping_prompt


@pytest.fixture
def pair_inputs():
    return {
        "clo_id": 7,
        "clo_text": "Explain the main processes in a forest ecosystem.",
        "plo_id": 12,
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


def read_pair_data(prompt):
    # Read the JSON back so we check the supplied values without depending on its spacing.
    return json.loads(prompt[prompt.index("{"):])


@pytest.mark.parametrize(
    ("clo_id", "plo_id", "expected_pair_id"),
    [(7, 12, "clo-7__plo-12"), (21, 34, "clo-21__plo-34")],
)
def test_prompt_includes_the_requested_pair_and_custom_mapping_levels(
    pair_inputs, clo_id, plo_id, expected_pair_id
):
    pair_inputs.update(clo_id=clo_id, plo_id=plo_id)

    prompt = build_mapping_prompt(**pair_inputs)

    assert read_pair_data(prompt) == {
        "id": expected_pair_id,
        "CLO": pair_inputs["clo_text"],
        "PLO": pair_inputs["plo_text"],
        "mapping_scales": pair_inputs["mapping_scales"],
    }


def test_prompt_preserves_quotes_line_breaks_and_unicode_in_supplied_text(pair_inputs):
    pair_inputs["clo_text"] = '  Explain "forest health".\nUse café examples and C:\\data\\plots.  '
    pair_inputs["plo_text"] = "Evaluate {soil, water} interactions.\nInclude forêt examples."
    pair_inputs["mapping_scales"][1]["description"] = 'Apply "new" concepts.\nGive evidence → conclusions.'

    pair_data = read_pair_data(build_mapping_prompt(**pair_inputs))

    assert pair_data["CLO"] == pair_inputs["clo_text"]
    assert pair_data["PLO"] == pair_inputs["plo_text"]
    assert pair_data["mapping_scales"] == pair_inputs["mapping_scales"]


def test_prompt_specifies_json_results_and_handles_multiple_or_no_matching_levels(pair_inputs):
    prompt = build_mapping_prompt(**pair_inputs)
    instructions = prompt[:prompt.index("{")]

    # These are the response rules we need to preserve when adapting the old prompt.
    assert "Return exactly one valid JSON object" in instructions
    for field in ("id", "is_mapped", "mapLabels", "explanation"):
        assert f'"{field}":' in instructions
    assert "A CLO may span multiple mapping levels." in instructions
    assert "Select only relevant abbreviations from the supplied levels, without duplicates." in instructions
    assert "If the outcomes align, include at least one matching abbreviation." in instructions
    assert "If the outcomes do not align, return an empty array." in instructions


def test_prompt_leaves_chat_formatting_and_thinking_control_to_the_client(pair_inputs):
    prompt = build_mapping_prompt(**pair_inputs)

    for marker in ("<|im_start|>", "<|im_end|>", "/no_think"):
        assert marker not in prompt


@pytest.mark.parametrize("field", ["clo_text", "plo_text"])
@pytest.mark.parametrize("blank_text", ["", " \t\n"])
def test_prompt_rejects_blank_outcome_text(pair_inputs, field, blank_text):
    pair_inputs[field] = blank_text

    with pytest.raises(ValueError, match="CLO and PLO text.*blank"):
        build_mapping_prompt(**pair_inputs)


def test_prompt_rejects_missing_mapping_levels(pair_inputs):
    pair_inputs["mapping_scales"] = []

    with pytest.raises(ValueError, match="At least one mapping scale"):
        build_mapping_prompt(**pair_inputs)


@pytest.mark.parametrize("abbreviation", ["", " \t\n"])
def test_prompt_rejects_blank_mapping_abbreviations(pair_inputs, abbreviation):
    pair_inputs["mapping_scales"][1]["abbreviation"] = abbreviation

    with pytest.raises(ValueError, match="abbreviations must not be blank"):
        build_mapping_prompt(**pair_inputs)


def test_prompt_rejects_duplicate_abbreviations_for_different_levels(pair_inputs):
    # The model returns abbreviations, so different levels cannot share the same label.
    pair_inputs["mapping_scales"][1]["abbreviation"] = "FOUND"

    with pytest.raises(ValueError, match="abbreviations must be unique"):
        build_mapping_prompt(**pair_inputs)
