from enum import Enum
from typing import List, Optional

from pydantic import BaseModel


class TopicSource(str, Enum):
    MATCH = "match"
    FONT = "font"
    KEYWORD = "keyword"


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
    source: TopicSource = TopicSource.MATCH

    model_config = {"frozen": True} # Used for checking equality in tests


class ExtractRequest(BaseModel):
    # Called initially when you want to extract text and topics from a PDF
    # Text extraction gives us useful font properties that are used by topic extraction in the same pass
    # Also used if text extraction failed and needs to be retried
    file: str  # base64-encoded PDF
    ocr_enabled: bool = False
    extraction_engine: str = "tesseract"
    ocr_threshold: int = 0
    material_type: Optional[str] = None
    existing_topics: list[str] = []


class RefreshTopicsRequest(BaseModel):
    # Called when you've already extracted text and topics, 
    # and just want to refresh keyword extracted topics (that are random to some degree)
    # and matched topics (that can differ if the Course-wide topics list changes anywhere)
    pages: list[PageContent]
    material_type: Optional[str] = None
    existing_topics: list[str] = []


class ExtractResponse(BaseModel):
    pages: list[PageContent]
    page_count: int
    topics: list[Topic]