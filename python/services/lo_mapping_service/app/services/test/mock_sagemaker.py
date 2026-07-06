"""
Test-only mock of a completed SageMaker batch-transform job.
"""
import json
from uuid import uuid4

import boto3

from app.core.config import Settings


class MockSageMaker:
    def __init__(self):
        settings = Settings()
        self._bucket = settings.BATCH_TRANSFORM_INPUT_S3_BUCKET
        self._region = settings.AWS_REGION or "ca-central-1"

    def write_output(self, course_id: int, program_id: int, suggestions: list[dict]) -> str:
        """Build the SageMaker output JSONL using _build_jsonl
        and uploads it to S3. Returns the output S3 URI."""
        jsonl = self._build_jsonl(suggestions)
        key = f"e2e-output/{course_id}-{program_id}-{uuid4()}.jsonl.out"
        s3 = boto3.client("s3", region_name=self._region)
        s3.put_object(Bucket=self._bucket, Key=key, Body=jsonl.encode("utf-8"))
        return f"s3://{self._bucket}/{key}"

    @staticmethod
    def _build_jsonl(suggestions: list[dict]) -> str:
        # Matches the real SageMaker output format
        lines = []
        for s in suggestions:
            inner = {
                "id": f"clo-{s['clo_id']}__plo-{s['plo_id']}",
                "CLO": "E2E CLO",
                "Accreditation_Standard": "E2E PLO",
                "is_mapped": bool(s["labels"]),  # empty labels -> unmapped (routes to N/A)
                "mapLabels": s["labels"],
                "explanation": "E2E happy path",
            }
            lines.append(json.dumps([{"generated_text": json.dumps(inner)}]))
        return "\n".join(lines)
