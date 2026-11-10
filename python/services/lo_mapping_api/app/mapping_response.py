import json
from typing import TypedDict

from app.mapping_prompt import MappingScaleOption


class MappingResponseError(ValueError):
    """The model returned an invalid or inconsistent mapping result."""


class MappingResult(TypedDict):
    id: str
    is_mapped: bool
    map_labels: list[str]
    explanation: str


def _read_json_object(fields: list[tuple[str, object]]) -> dict[str, object]:
    result = {}
    for name, value in fields:
        # json.loads normally keeps the last value when a field appears twice.
        if name in result:
            raise MappingResponseError("Mapping response must not repeat JSON fields.")
        result[name] = value
    return result


def parse_mapping_response(
    response_text: str,
    *,
    expected_pair_id: str,
    mapping_scales: list[MappingScaleOption],
) -> MappingResult:
    """Validate a model answer against the pair and mapping levels used in the prompt."""
    if not isinstance(response_text, str):
        raise MappingResponseError("Mapping response must be JSON text.")

    try:
        response = json.loads(response_text, object_pairs_hook=_read_json_object)
    except json.JSONDecodeError:
        # Don't guess a result from malformed text; that could hide a failed mapping.
        raise MappingResponseError("Mapping response must be valid JSON.") from None

    if not isinstance(response, dict):
        raise MappingResponseError("Mapping response must be a JSON object.")

    expected_fields = {"id", "is_mapped", "mapLabels", "explanation"}
    if set(response) != expected_fields:
        raise MappingResponseError(
            "Mapping response must contain exactly id, is_mapped, mapLabels, and explanation."
        )

    pair_id = response["id"]
    if not isinstance(pair_id, str) or pair_id != expected_pair_id:
        raise MappingResponseError("Mapping response ID does not match the requested pair.")

    is_mapped = response["is_mapped"]
    # Strings like "false" and numbers like 0 or 1 are not JSON booleans.
    if not isinstance(is_mapped, bool):
        raise MappingResponseError("Mapping response is_mapped must be a boolean.")

    map_labels = response["mapLabels"]
    if not isinstance(map_labels, list) or any(not isinstance(label, str) for label in map_labels):
        raise MappingResponseError("Mapping response mapLabels must be an array of strings.")
    if len(set(map_labels)) != len(map_labels):
        raise MappingResponseError("Mapping response must not contain duplicate mapping labels.")

    allowed_labels = {scale["abbreviation"] for scale in mapping_scales}
    if any(label not in allowed_labels for label in map_labels):
        raise MappingResponseError("Mapping response contains an unknown mapping label.")

    if is_mapped and not map_labels:
        raise MappingResponseError("An aligned pair must have at least one mapping label.")
    if not is_mapped and map_labels:
        raise MappingResponseError("An unaligned pair must have no mapping labels.")

    explanation = response["explanation"]
    if not isinstance(explanation, str) or not explanation.strip():
        raise MappingResponseError("Mapping response explanation must be a nonblank string.")

    # A valid unmapped result returns normally; invalid answers raise the error above.
    return {
        "id": pair_id,
        "is_mapped": is_mapped,
        "map_labels": map_labels,
        "explanation": explanation,
    }
