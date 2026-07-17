import base64

from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware

from app.core.config import settings
from app.core.logging_config import logger
from app.schemas import ExtractRequest, ExtractResponse, PageContent
from app.services.text_readers import document_extractor
from app.services.topic_extraction import type_specific_handlers


app = FastAPI(
    title="Course Material Text & Topic Extraction Service",
    version="0.1.0",
    docs_url="/docs",
    redoc_url="/redoc",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.ALLOWED_ORIGINS,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"]
)


@app.get("/health")
async def health_check() -> dict[str, str]:
    return {"status": "ok"}


@app.post("/extract", response_model=ExtractResponse)
def extract(request: ExtractRequest) -> ExtractResponse:
    """Extract per-page text and topics from a PDF in a single pass."""
    try:
        file_bytes = base64.b64decode(request.file)
        pages, page_count = document_extractor.extract(
            file_bytes,
            request.ocr_enabled,
            request.extraction_engine,
            request.ocr_threshold,
        )
        # TODO: See how best to handle text-only input from Textract here
        handler = type_specific_handlers.get_handler(request.material_type)
        topics = handler.extract_topics(pages, request.existing_topics)

        return ExtractResponse(
            pages=[
                PageContent(page_number=page["page_number"], content="\n".join(line["text"] for line in page["lines"]))
                for page in pages
                if page["lines"]
            ],
            page_count=page_count,
            topics=topics,
        )
    except Exception as e:
        logger.error(f"Extraction failed: {e}")
        raise HTTPException(status_code=500, detail=str(e))
