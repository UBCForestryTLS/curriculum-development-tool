import boto3
import httpx
import json
import re
from decimal import Decimal

from app.core.config import settings
from app.core.logging_config import logger
from app.services.lo_mapping_request_dynamo_db_request import LOMappingRequestDynamoDBRecord

boto_session = boto3.Session(
    aws_access_key_id=settings.ACCESS_KEY,
    aws_secret_access_key=settings.SECRET_KEY,
    region_name=settings.AWS_REGION,
)

s3 = boto_session.client("s3")

lo_mapping_request_store = LOMappingRequestDynamoDBRecord()


def parse_s3_uri(s3_uri: str) -> tuple[str, str]:
    if not s3_uri.startswith("s3://"):
        raise ValueError(f"Invalid S3 URI: {s3_uri}")

    without_scheme = s3_uri[len("s3://"):]
    parts = without_scheme.split("/", 1)

    bucket = parts[0]
    key    = parts[1] if len(parts) > 1 else ""

    return bucket, key


def read_s3_jsonl(s3_uri: str) -> list[dict]:
    """Download .jsonl file from S3 and return each line as a parsed dict."""
    bucket, key = parse_s3_uri(s3_uri)
    logger.info("Reading S3 output: s3://%s/%s", bucket, key)

    response = s3.get_object(Bucket=bucket, Key=key)
    body     = response["Body"].read().decode("utf-8")

    lines  = [line.strip() for line in body.splitlines() if line.strip()]
    parsed = []
    for i, line in enumerate(lines):
        try:
            parsed.append(json.loads(line))
        except json.JSONDecodeError as e:
            logger.warning("Skipping malformed JSONL line %d: %s — %s", i, line, e)

    logger.info("Parsed %d line(s) from %s", len(parsed), s3_uri)
    return parsed


def parse_composite_id(composite_id: str) -> tuple[str | None, str | None]:
    """
    Parse a composite ID of the form  'clo-{clo_id}__plo-{plo_id}'
    and return (clo_id, plo_id).

    Returns (None, None) if the format is not recognised.
    """
    match = re.match(r"clo-(?P<clo_id>[^_]+)__plo-(?P<plo_id>.+)", composite_id or "")
    if not match:
        logger.warning("Could not parse composite id '%s'.", composite_id)
        return None, None

    return match.group("clo_id"), match.group("plo_id")


def extract_generated_text(line: dict) -> str | None:
    """
    Pull the raw generated text out of a JSONL file. Handles both list-wrapped and plain dict responses
    """
    if isinstance(line, list):
        line = line[0] if line else None
    if not isinstance(line, dict):
        return None
    return line.get("generated_text")


def strip_think_tag(text: str) -> str:
    """
    Return only the content that follows the closing </think> tag. If no such tag exists the full text is returned unchanged
    """
    if "</think>" in text:
        return text.split("</think>", 1)[-1].strip()
    return text.strip()


def extract_label_and_explanation(generated_text: str) -> dict:
    """
    Try to parse the generated text as JSON first; fall back to line scanning.

    Expected JSON shape:
        {
            "id":          "clo-1__plo-2",   # optional here; handled upstream
            "CLO":         "...",            
            "Accreditation_Standard": "...", 
            "is_mapped":   true or false,    
            "explanation": "...",
            "mapLabels":   [...]
        }
    """
    try:
        parsed = json.loads(generated_text)
        if isinstance(parsed, dict):
            return {
                "id":          parsed.get("id", None),
                "CLO":        parsed.get("CLO", ""),
                "Accreditation_Standard": parsed.get("Accreditation_Standard", ""),
                "is_mapped":  parsed.get("is_mapped", False),
                "explanation": parsed.get("explanation", None),
                "map_labels":  parsed.get("mapLabels", [])
            }
    except (json.JSONDecodeError, TypeError):
        pass

    id = clo = accreditation_standard = explanation = None
    for text_line in generated_text.splitlines():
        text_line = text_line.strip()
        if text_line.lower().startswith("explanation:"):
            explanation = text_line.split(":", 1)[1].strip()
        elif text_line.lower().startswith("clo:"):
            clo = text_line.split(":", 1)[1].strip()
        elif text_line.lower().startswith("accreditation_standard:"):
            accreditation_standard = text_line.split(":", 1)[1].strip()
        elif text_line.lower().startswith("id:"):
            id = text_line.split(":", 1)[1].strip()
    return {
        "id": id if id else None,
        "CLO": clo if clo else "",
        "Accreditation_Standard": accreditation_standard if accreditation_standard else "",
        "explanation": explanation,
        "map_labels":  [],
        "is_mapped": False
        }


def process_jsonl_lines(lines: list[dict]) -> list[dict]:
    """
    For every JSONL line:
      - Extract generated_text
      - Strip everything up to and including </think>.
      - Parse the remaining text for clo, mapped_labels, etc.
      - Parse clo_id / plo_id from the line's 'id' field.

    Returns a list of result objects ready to be sent to the API
    """
    results = []

    for i, line in enumerate(lines):
        try:
            raw_text = extract_generated_text(line)
            if raw_text is None:
                logger.warning("Line %d has no generated_text — skipping.", i)
                continue

            clean_text = strip_think_tag(raw_text)
            extracted  = extract_label_and_explanation(clean_text)
            composite_id     = extracted.get("id", "")
            clo_id, plo_id   = parse_composite_id(composite_id)
            
            if clo_id is None or plo_id is None:
                logger.warning("Line %d has invalid or missing composite id '%s' — skipping.", i, composite_id)
                continue

            results.append({
                "clo_id":      clo_id,
                "plo_id":      plo_id,
                "CLO": extracted["CLO"],
                "Accreditation_Standard": extracted["Accreditation_Standard"],
                "is_mapped": extracted["is_mapped"],
                "explanation": extracted["explanation"],
                "map_labels":  extracted["map_labels"],
            })

        except Exception as e:
            logger.warning("Failed to process line %d: %s — %s", i, line, e)

    return results


def delete_dynamodb_record(record_id: str) -> None:
    """Delete the DynamoDB record once its output has been fully processed"""
    try:
        lo_mapping_request_store.delete_request(record_id)
        logger.info("Deleted DynamoDB record '%s'.", record_id)
    except Exception as e:
        logger.error("Failed to delete DynamoDB record '%s': %s", record_id, e)
        # Not re-raising since failure to delete should not block processing of other records



async def send_results_to_external_api(record_id: str, results: list[dict], record: dict) -> None:
    """POST all extracted results for a single record to the external API"""
    # DynamoDB returns numeric attributes as Decimal, which json doesn't handle.
    course_id  = record.get("course_id")
    program_id = record.get("program_id")
    payload = {
        "request_id": record_id,
        "course_id":  int(course_id)  if isinstance(course_id, Decimal)  else course_id,
        "program_id": int(program_id) if isinstance(program_id, Decimal) else program_id,
        "status":     record.get("status"),
        "results":    results,   # list of {clo_id, plo_id, clo, accreditation_standard, explanation, map_labels, is_mapped}
    }

    async with httpx.AsyncClient(timeout=30) as client:
        response = await client.post(settings.LARAVEL_API_URL, json=payload)
        response.raise_for_status()
        logger.info(
            "Sent %d result(s) for record '%s' to external API — HTTP %s.",
            len(results), record_id, response.status_code,
        )


async def process_records(records: list) -> dict:
    """
    For each record:
      - get the S3 output path from the DynamoDB record
      - Read and parse the JSONL file
      - Strip <think> blocks and extract fields from each line
      - Send all results for this record to the external API
      - Delete the DynamoDB record

    A failure in one record is logged but record is not deleted.

    Returns {"succeeded": [...], "failed": [...]} of request_ids so callers can
    distinguish a successful delivery from a transient failure.
    """
    logger.info("Starting processing for %d record(s).", len(records))

    succeeded: list[str] = []
    failed:    list[str] = []

    for record in records:
        record_id = record.get("request_id")
        record_status = record.get("status")
        logger.info("Processing record '%s'.", record_id)

        try:
            if record_status == "AWAITING_COMPLETION_FAILED":
                logger.info(
                    "Record '%s' is marked as failed; notifying external API without reading S3 output.",
                    record_id,
                )
                await send_results_to_external_api(record_id, [], record)
                delete_dynamodb_record(record_id)
                succeeded.append(record_id)
                continue

            output_s3_uri = record.get("output_s3_path")
            if not output_s3_uri:
                # Mirror what create_request now does: <OUTPUT_S3_URI>/<input_filename>.out
                input_s3_path = record.get("input_s3_path", "")
                output_prefix = (settings.OUTPUT_S3_URI or "").rstrip("/")
                input_filename = input_s3_path.rsplit("/", 1)[-1] if input_s3_path else ""
                if not output_prefix or not input_filename:
                    logger.warning("Record '%s' is missing output_s3_path and OUTPUT_S3_URI/input_s3_path can't fill it — skipping.", record_id)
                    failed.append(record_id)
                    continue
                output_s3_uri = f"{output_prefix}/{input_filename}.out"

            lines = read_s3_jsonl(output_s3_uri)

            results = process_jsonl_lines(lines)
            logger.info(
                "Extracted %d result(s) for record '%s'.",
                len(results), record_id,
            )

            await send_results_to_external_api(record_id, results, record)

            delete_dynamodb_record(record_id)
            succeeded.append(record_id)

        except Exception as e:
            logger.error(
                "Failed to process record '%s': %s — skipping.",
                record_id, e,
            )
            failed.append(record_id)
            continue

    logger.info("Finished processing %d record(s). Succeeded=%d Failed=%d", len(records), len(succeeded), len(failed))
    