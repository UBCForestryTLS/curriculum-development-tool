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
    where each page is a dict with {page_number, text, lines}, 
    and lines is a list of {text, size, bold}:
      - for readable pages: real font size extracted with bold flag (True/False)
      - for scanned pages (Tesseract OCR): word-box height estimates size; bold is unknown (None)
      - for scanned pages (Textract OCR): no font data, so lines is empty
    """
    if ocr_enabled and extraction_engine == "textract" and settings.TEXTRACT_ENABLED:
        return _from_textract(file_bytes)

    doc = pymupdf.open(stream=file_bytes, filetype="pdf")
    page_count = doc.page_count
    logger.info(f"Extracting {page_count}-page document (ocr_enabled={ocr_enabled})")

    pages: list[dict] = []
    try:
        for index, page in enumerate(doc, start=1):
            text_layer = page.get_text("text")
            if ocr_enabled and extraction_engine == "tesseract" and len(text_layer.strip()) <= ocr_threshold:
                text, lines = _from_ocr(page)
            else:
                text, lines = _from_text_layer(page, text_layer)
            pages.append({"page_number": index, "text": text.strip(), "lines": lines})
    finally:
        doc.close()

    return pages, page_count


def _from_text_layer(page, text_layer: str) -> tuple[str, list[dict]]:
    lines: list[dict] = []
    for block in page.get_text("dict").get("blocks", []):
        for line in block.get("lines", []):
            spans = line.get("spans", [])
            text = "".join(span.get("text", "") for span in spans).strip()
            if not text:
                continue
            size = max((span.get("size", 0.0) for span in spans), default=0.0)
            bold = any(_is_bold(span) for span in spans)
            lines.append({"text": text, "size": round(size, 1), "bold": bold})
    return text_layer, lines


def _is_bold(span) -> bool:
    if span.get("flags", 0) & _BOLD_FLAG:
        return True
    return "bold" in span.get("font", "").lower()


def _from_ocr(page) -> tuple[str, list[dict]]:
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
        # size, so `size` is comparable to actual font size, and a single size threshold
        # works for both readable and scanned pages.
        height_px = statistics.median(height for height, _ in words)
        size_pt = height_px * 72 / OCR_RENDER_DPI
        # Cannot determine if bold with Tesseract
        lines.append({"text": text, "size": round(float(size_pt), 1), "bold": None})

    full_text = "\n".join(line["text"] for line in lines)
    return full_text, lines


# --- AWS Textract ---
# TODO: Bit messy, clean up - can move some logic away from here

POLLING_INTERVAL_SECONDS = 2

def _from_textract(file_bytes: bytes) -> tuple[list[dict], int]:
    """Extract per-page text with AWS Textract. Pages carry no font lines."""
    import boto3  # lazy: only needed when the Textract engine is used

    with pymupdf.open(stream=file_bytes, filetype="pdf") as doc:
        page_count = doc.page_count
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
    return [{"page_number": num, "text": grouped[num], "lines": []} for num in sorted(grouped)]


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
