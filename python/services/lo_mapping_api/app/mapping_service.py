from app.bedrock_client import BedrockClient
from app.mapping_prompt import MappingScaleOption, build_mapping_prompt
from app.mapping_response import MappingResult, parse_mapping_response


def map_pair(
    *,
    clo_id: int,
    clo_text: str,
    plo_id: int,
    plo_text: str,
    mapping_scales: list[MappingScaleOption],
    bedrock_client: BedrockClient,
) -> MappingResult:
    """Map one CLO-PLO pair using the supplied client and return the validated result.

    Errors reach the caller; a valid unmapped result returns normally.
    """
    # Build and validate first so invalid inputs never reach Bedrock.
    prompt = build_mapping_prompt(
        clo_id=clo_id,
        clo_text=clo_text,
        plo_id=plo_id,
        plo_text=plo_text,
        mapping_scales=mapping_scales,
    )
    response_text = bedrock_client.generate_text(prompt)

    return parse_mapping_response(
        response_text,
        expected_pair_id=f"clo-{clo_id}__plo-{plo_id}",
        mapping_scales=mapping_scales,
    )
