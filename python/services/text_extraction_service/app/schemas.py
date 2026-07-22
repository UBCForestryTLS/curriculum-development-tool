from typing import List, Optional

from pydantic import BaseModel


class ExtractedLine(BaseModel):
    text: str
    size: float | None = None
    bold: bool | None = None


class ExtractedPage(BaseModel):
    page_number: int
    lines: list[ExtractedLine]


class PageContent(BaseModel):
    page_number: int
    content: str


class Topic(BaseModel):
    topic: str
    score: float


class ExtractRequest(BaseModel):
    file: str  # base64-encoded PDF
    ocr_enabled: bool = False
    extraction_engine: str = "tesseract"
    ocr_threshold: int = 0
    material_type: Optional[str] = None
    existing_topics: list[str] = []


class ExtractResponse(BaseModel):
    pages: list[PageContent]
    page_count: int
    topics: list[Topic]