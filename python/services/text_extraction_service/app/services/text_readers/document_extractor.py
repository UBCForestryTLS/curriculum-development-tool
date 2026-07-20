import io
import os
import statistics
import time

import pymupdf
import pytesseract
from PIL import Image

from app.core.config import settings
from app.core.logging_config import logger


OCR_RENDER_DPI = 300
MIN_OCR_CONFIDENCE = 40

# PyMuPDF flag bit for bold text.
_BOLD_FLAG = 0b10000


def extract(
    file_bytes: bytes,
    ocr_enabled: bool,
    extraction_engine: str,
    ocr_threshold: int,
) -> tuple[list[dict], int]:
    """Extract text per page and annotate with font properties per line

    Returns (pages, page_count),
    where each page is a dict with {page_number, lines}, 
    and lines is a list of {text, size, bold}:
      - for readable pages: real font size extracted with bold flag (True/False)
      - for scanned pages (Tesseract OCR): word-box height estimates size; bold is unknown (None)
      - for scanned pages (Textract OCR): size and bold are unknown (None)
    """
    doc : pymupdf.Document = pymupdf.open(stream=file_bytes, filetype="pdf")
    page_count = doc.page_count

    if ocr_enabled and extraction_engine == "textract":
        doc.close()
        return _from_textract(file_bytes, page_count)

    logger.info(f"Extracting {page_count}-page document (ocr_enabled={ocr_enabled})")

    pages: list[dict] = []
    try:
        for i in range(page_count):
            page: pymupdf.Page = doc[i]
            if ocr_enabled and extraction_engine == "tesseract" and len(page.get_text("text").strip()) <= ocr_threshold:
                lines = _from_ocr(page)
            else:
                lines = _from_text_layer(page)
            pages.append({"page_number": i + 1, "lines": lines})
    finally:
        doc.close()

    return pages, page_count


def _from_text_layer(page) -> list[dict]:
    lines: list[dict] = []
    for block in page.get_text("dict", flags=pymupdf.TEXT_COLLECT_STYLES).get("blocks", []):
        for line in block.get("lines", []):
            spans = line.get("spans", [])
            text = "".join(span.get("text", "") for span in spans).strip()
            if not text:
                continue
            size = max((span.get("size", 0.0) for span in spans), default=0.0) # Largest font size in line treated as line font size
            bold = any(_is_bold(span) for span in spans)
            lines.append({"text": text, "size": round(size, 1), "bold": bold})
    return lines


def _is_bold(span) -> bool:
    if span.get("flags", 0) & _BOLD_FLAG:
        return True
    return "bold" in span.get("font", "").lower()


def _from_ocr(page) -> list[dict]:
    pixmap = page.get_pixmap(dpi=OCR_RENDER_DPI)
    image = Image.open(io.BytesIO(pixmap.tobytes("png")))
    data = pytesseract.image_to_data(image, output_type=pytesseract.Output.DICT)

    # Group words into lines, keeping each word's box height (approx. font size).
    grouped: dict[tuple, list[tuple[int, str]]] = {}
    for i, raw_word in enumerate(data["text"]):
        word = raw_word.strip()
        if not word:
            continue
        try:
            confidence = float(data["conf"][i])
        except (ValueError, TypeError):
            confidence = -1.0
        if confidence < MIN_OCR_CONFIDENCE:
            continue
        key = (data["block_num"][i], data["par_num"][i], data["line_num"][i])
        grouped.setdefault(key, []).append((data["height"][i], word))

    lines: list[dict] = []
    for _, words in sorted(grouped.items()):
        text = " ".join(word for _, word in words).strip()
        if not text:
            continue
        # Convert median box height (px at OCR_RENDER_DPI) to an approximate point
        # size, so size is comparable to actual font size, and a single size threshold
        # works for both readable and scanned pages.
        height_px = statistics.median(height for height, _ in words)
        size_pt = height_px * 72 / OCR_RENDER_DPI
        # Cannot easily determine if bold with Tesseract
        lines.append({"text": text, "size": round(float(size_pt), 1), "bold": None})

    return lines


# --- AWS Textract ---
# TODO: Bit messy, clean up - can move some logic away from here

POLLING_INTERVAL_SECONDS = 2

def _from_textract(file_bytes: bytes, page_count: int) -> tuple[list[dict], int]:
    """Extract per-page text with AWS Textract. Pages carry no font lines."""
    import boto3  # lazy: only needed when the Textract engine is used

    logger.info(f"Textract extracting {page_count}-page document")

    session = boto3.Session(
        aws_access_key_id=settings.AWS_ACCESS_KEY_ID,
        aws_secret_access_key=settings.AWS_SECRET_ACCESS_KEY,
        region_name=settings.AWS_REGION,
    )
    textract = session.client("textract")

    # Single-page PDFs can use the synchronous API (no S3 upload needed).
    if page_count == 1:
        try:
            grouped: dict[int, str] = {}
            _accumulate_textract(textract.detect_document_text(Document={"Bytes": file_bytes}), grouped)
            return _textract_pages(grouped), page_count
        except Exception as e:
            logger.info(f"Synchronous Textract failed: {e}; falling back to async")

    # Multi-page (or sync failure): async detection reads the file from S3.
    s3 = session.client("s3")
    s3_key = f"textract-jobs/{int(time.time())}-{os.urandom(4).hex()}.pdf"
    s3.put_object(Bucket=settings.AWS_S3_BUCKET, Key=s3_key, Body=file_bytes)
    job_id = textract.start_document_text_detection(
        DocumentLocation={"S3Object": {"Bucket": settings.AWS_S3_BUCKET, "Name": s3_key}}
    )["JobId"]
    logger.info(f"Started async Textract job {job_id}")
    return _wait_for_textract(textract, job_id), page_count


def _accumulate_textract(response, grouped: dict) -> None:
    for block in response["Blocks"]:
        if block["BlockType"] == "PAGE":
            grouped.setdefault(block["Page"], "")
        elif block["BlockType"] == "LINE" and block["Page"] in grouped:
            grouped[block["Page"]] += block["Text"] + "\n"


def _textract_pages(grouped: dict) -> list[dict]:
    pages: list[dict] = []
    for num in sorted(grouped):
        text = grouped[num].strip()
        lines = [
            {"text": line.strip(), "size": None, "bold": None}
            for line in text.split("\n")
            if line.strip()
        ]
        pages.append({"page_number": num, "lines": lines})
    return pages


def _wait_for_textract(textract, job_id, max_wait_seconds: int = 600) -> list[dict]:
    start = time.time()
    while time.time() - start < max_wait_seconds:
        response = textract.get_document_text_detection(JobId=job_id)
        status = response["JobStatus"]
        logger.info(f"Textract job {job_id} status: {status}")
        if status == "SUCCEEDED":
            grouped: dict[int, str] = {}
            next_token = None
            while True:
                page = (
                    textract.get_document_text_detection(JobId=job_id, NextToken=next_token)
                    if next_token else response
                )
                _accumulate_textract(page, grouped)
                next_token = page.get("NextToken")
                if not next_token:
                    break
            return _textract_pages(grouped)
        if status == "FAILED":
            raise Exception(f"Textract job {job_id} failed")
        time.sleep(POLLING_INTERVAL_SECONDS)
    raise Exception(f"Textract job {job_id} did not complete within {max_wait_seconds}s")
