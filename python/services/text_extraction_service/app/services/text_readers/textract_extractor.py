import os
import time

import boto3

from app.core.config import Settings
from app.core.logging_config import logger
from app.schemas import ExtractedLine, ExtractedPage

POLLING_INTERVAL_SECONDS = 2
MAX_WAIT_SECONDS = 600


class TextractClient:
    """Manages AWS Textract interactions for document text extraction."""

    def __init__(self, settings: Settings | None = None):
        self.settings = settings or Settings()

    def extract_text(self, file_bytes: bytes, page_count: int) -> tuple[list[ExtractedPage], int]:
        """Extract text from a PDF document using AWS Textract.

        For single-page documents, uses direct synchronous Textract call.
        For multi-page documents, uploads to S3 and uses async Textract job.

        Returns (pages, page_count), where each page is an ExtractedPage with
        page_number and lines. Lines are ExtractedLine objects with size=None
        and bold=None since Textract does not provide font metadata.
        """
        self._validate_settings()
        session = self._create_boto_session()

        if page_count == 1:
            try:
                return self._extract_sync(session, file_bytes), page_count
            except Exception as e:
                logger.info("Synchronous Textract failed: %s; falling back to async", e)
        else:
            return self._extract_async(session, file_bytes), page_count

    def _extract_sync(self, session: boto3.Session, file_bytes: bytes) -> list[ExtractedPage]:
        """Single-page extraction using the synchronous detect_document_text API."""
        response = session.client("textract").detect_document_text(Document={"Bytes": file_bytes})
        return _parse_textract_response(response)

    def _extract_async(self, session: boto3.Session, file_bytes: bytes) -> list[ExtractedPage]:
        """Multi-page extraction using async start/get document text detection via S3."""
        textract = session.client("textract")
        s3 = session.client("s3")

        s3_key = f"textract-jobs/{int(time.time())}-{os.urandom(4).hex()}.pdf"
        s3.put_object(Bucket=self.settings.AWS_S3_BUCKET, Key=s3_key, Body=file_bytes)

        job_id = textract.start_document_text_detection(
            DocumentLocation={"S3Object": {"Bucket": self.settings.AWS_S3_BUCKET, "Name": s3_key}}
        )["JobId"]
        logger.info("Started async Textract job %s", job_id)

        return self._poll_job(textract, job_id)

    def _poll_job(self, textract, job_id: str) -> list[ExtractedPage]:
        """Poll Textract until the job succeeds, fails, or times out."""
        start = time.time()
        while time.time() - start < MAX_WAIT_SECONDS:
            response = textract.get_document_text_detection(JobId=job_id)
            status = response["JobStatus"]
            logger.info("Textract job %s status: %s", job_id, status)

            if status == "SUCCEEDED":
                return self._collect_results(textract, job_id, response)
            if status == "FAILED":
                raise RuntimeError("Textract job %s failed", job_id)

            time.sleep(POLLING_INTERVAL_SECONDS)

        raise TimeoutError("Textract job %s did not complete within %ss", job_id, MAX_WAIT_SECONDS)

    def _collect_results(self, textract, job_id: str, initial_response: dict) -> list[ExtractedPage]:
        """Paginate through all Textract results and parse into pages."""
        all_blocks = initial_response["Blocks"]
        next_token = initial_response.get("NextToken")

        while next_token:
            page = textract.get_document_text_detection(JobId=job_id, NextToken=next_token)
            all_blocks.extend(page["Blocks"])
            next_token = page.get("NextToken")

        return _parse_textract_response({"Blocks": all_blocks})

    def _validate_settings(self) -> None:
        """Ensure required AWS configuration is present before making API calls."""
        s = self.settings
        if not s.AWS_ACCESS_KEY_ID or not s.AWS_SECRET_ACCESS_KEY:
            raise ValueError("AWS credentials (ACCESS_KEY_ID, SECRET_ACCESS_KEY) are not set")
        if not s.AWS_REGION:
            raise ValueError("AWS_REGION is not set")
        if not s.AWS_S3_BUCKET:
            raise ValueError("AWS_S3_BUCKET is not set")

    def _create_boto_session(self) -> boto3.Session:
        return boto3.Session(
            aws_access_key_id=self.settings.AWS_ACCESS_KEY_ID,
            aws_secret_access_key=self.settings.AWS_SECRET_ACCESS_KEY,
            region_name=self.settings.AWS_REGION,
        )


def _parse_textract_response(response: dict) -> list[ExtractedPage]:
    """Parse a Textract response into a list of ExtractedPage objects."""
    blocks = response.get("Blocks", [])
    pages_by_number: dict[int, list[str]] = {}
    for block in blocks:
        page_num = block.get("Page")
        if block["BlockType"] == "PAGE":
            pages_by_number.setdefault(page_num, [])
        elif block["BlockType"] == "LINE" and page_num in pages_by_number:
            pages_by_number[page_num].append(block["Text"])

    pages: list[ExtractedPage] = []
    for page_num in sorted(pages_by_number):
        lines = [
            ExtractedLine(text=line.strip())
            for line in pages_by_number[page_num]
            if line.strip()
        ]
        pages.append(ExtractedPage(page_number=page_num, lines=lines))

    return pages
