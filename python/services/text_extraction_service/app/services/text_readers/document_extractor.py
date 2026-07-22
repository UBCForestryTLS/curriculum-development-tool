import io
import statistics

import pymupdf
import pytesseract
from PIL import Image

from app.core.config import settings
from app.core.logging_config import logger
from app.services.text_readers.textract_extractor import TextractClient

OCR_RENDER_DPI = 300
MIN_OCR_CONFIDENCE = 40

# PyMuPDF flag bit for bold text.
_BOLD_FLAG = 0b10000

_textract_client: TextractClient | None = None


def _get_textract_client() -> TextractClient:
    """Lazy singleton for the Textract client (avoids boto3 import at module load)."""
    global _textract_client
    if _textract_client is None:
        _textract_client = TextractClient(settings)
    return _textract_client


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
    doc: pymupdf.Document = pymupdf.open(stream=file_bytes, filetype="pdf")
    page_count = doc.page_count

    if ocr_enabled and extraction_engine == "textract":
        doc.close()
        return _get_textract_client().extract_text(file_bytes, page_count)

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
