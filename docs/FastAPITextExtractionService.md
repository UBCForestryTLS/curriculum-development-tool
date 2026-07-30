# FastAPI Text Extraction Service

## Overview

The Text Extraction Service is a FastAPI application that extracts per-page text, and file-wide topics from PDF course materials. 

Text extraction is performed using a PDF parser, or OCR if required (using Tesseract or Textract). Topic extraction uses a combination of font properties and BERTopic scores to identify suggested topics.

## Primary Responsibility

This service is responsible for:

- accepting text-topic extraction and topic refresh requests
- extracting text content from PDF files, page by page, and using OCR if requested
- extracting potential topics from document text by
  - using BERTopic to extract keywords/phrases from the text
  - using Font properties (or estimated font properties with OCR) and the material type to identify potential topics
  - finding existing course topics in course text

## API

### `GET /health`

Returns a simple response:

```json
{
  "status": "ok"
}
```

### `POST /extract`

The main endpoint. Accepts an [`ExtractRequest`](../python/services/text_extraction_service/app/schemas.py) with:

- `file` (string, required): base64-encoded PDF content
- `ocr_enabled` (boolean, default `false`): whether to apply OCR on low-text pages
- `extraction_engine` (string, default `"tesseract"`): `"tesseract"` or `"textract"`
- `ocr_threshold` (integer, default `0`): page text length below which OCR is triggered (see note below)
- `material_type` (string, optional): `"slides"`, `"article"`, or `null` for default handling
- `existing_topics` (list of strings, default `[]`): course topics to match against extracted text

Note on `ocr_threshold`: When using `tesseract`, we first count the number of non-whitespace characters on the page that are already text-readable without OCR. If the count is *greater* than `ocr_threshold`, then we simply take the already readable text as that page's text. If the count is equal to or less than `ocr_threshold`, then we convert the page to an image and perform OCR on it. If `ocr_threshold` is set to `0`, then we will only perform OCR if the page has no encoded text (for example, a scanned page). This threshold especially saves resouces when dealing with slides, as there are some pages that have figures and charts that need OCR, but others with text.

Note (TODO): Having both `ocr_enabled` and `extraction_engine` is slightly redundant - we could have a third `extraction_engine` option be `"text-only"` or similar to cover both. However, this set up allows us to easily remove textract if we need to, as that was discussed to help simplify the set up but later retained as the service was modularized further. A potential improvement is having an `int` field with `0` correspond to text-only, and `1, 2, ...` correspond to OCR engines.

Example request:

```json
{
  "file": "JVBERi0xLjQK...",
  "ocr_enabled": true,
  "extraction_engine": "tesseract",
  "ocr_threshold": 50,
  "material_type": "slides",
  "existing_topics": ["Climate Change", "Forest Ecology"]
}
```

Returns an [`ExtractResponse`](../python/services/text_extraction_service/app/schemas.py) with:

- `pages`: list of `{ page_number, content }` for each page with extracted text
- `page_count`: total number of pages in the PDF
- `topics`: list of `{ topic, score, source }` extracted from the document

### `POST /refresh-topics`

Re-extracts topics from existing page content without re-running text extraction. Accepts a [`RefreshTopicsRequest`](../python/services/text_extraction_service/app/schemas.py) with:

- `pages` (list, required): list of `{ page_number, content }` objects from a previous extraction
- `material_type` (string, optional): `"slides"`, `"article"`, or `null`
- `existing_topics` (list of strings, default `[]`): course topics to match against

Example request:

```json
{
  "pages": [
    { "page_number": 1, "content": "Introduction to Forest Ecology" },
    { "page_number": 2, "content": "Climate Change Impacts" }
  ],
  "material_type": "article",
  "existing_topics": ["Climate Change", "Forest Ecology"]
}
```

Returns the same `ExtractResponse` format as `/extract`.

## Text Extraction

The extraction pipeline in [`document_extractor.py`](../python/services/text_extraction_service/app/services/text_readers/document_extractor.py) works as follows:

1. PDF is opened with PyMuPDF
2. For each page:
   - If OCR is enabled and the page has little text (below `ocr_threshold`), the page is processed with Tesseract or Textract (see **OCR Engines** below).
   - Otherwise, text is extracted directly from the text layer with font size and font weight.d
3. Each page returns a list of lines with `{ text, size, bold }` metadata. The `size` and `bold` may be `None` depending on the extraction engine.

### OCR Engines

- **Tesseract**: Renders page to image at 300 DPI, groups words into lines, estimates font size from word-box height. Estimating whether the font is bold or not is possible but quite complex, so isn't implemented for now.
- **AWS Textract**: Single-page PDF extraction uses a synchronous call. Multi-page PDFs are uploaded to S3, processed asynchronously, and the service polls for completion. Textract returns line text only, no font metadata. However, Textract is much faster than Tesseract for large PDFs.

## Topic Extraction Pipeline

Topic extraction is handled by [`type_specific_handlers.py`](../python/services/text_extraction_service/app/services/topic_extraction/type_specific_handlers.py) with material-type-specific handlers. Currently, there is a specific handler for Slides, and one for Articles, as well as a default handler.

### BERTopic Extraction

The BERTopic extractor in [`bertopic_extractor.py`](../python/services/text_extraction_service/app/services/topic_extraction/bertopic_extractor.py):

1. Lemmatizes text with spaCy to collapse plurals
2. Splits text into overlapping windows
3. Fits BERTopic with the `all-mpnet-base-v2` embedding model.
4. Extracts top keywords from each cluster
5. Returns the `TOPICS_COUNT` (currently 20) best-scoring topics. This limit should ideally never be reached, and is only there to prevent the database exploding with topics. We can increase the limit any time.

## Runtime Dependencies

Outside of what is installed via `pip`, you will need two dependencies:

**Tesseract**: Local OCR engine. Install  `tesseract-ocr` via package manager or from the [official Tesseract page](https://tesseract-ocr.github.io/tessdoc/Installation.html) and ensure `tesseract` is on your `PATH`. English language data (`eng`) must be included.

Verify Tesseract is reachable and has English data:

```
tesseract --version
tesseract --list-langs # eng must appear in output
```

**spaCy English model**: The English NLP model used by the BERTopic extraction logic for Text lematization. After running `pip install -r requirements.txt`, run
```
python -m spacy download en_core_web_sm
```
Note that `_sm` stands for 'small', and is the lightest model. Heavier models like `en_core_web_lg` may yield better results, but also use more resources and space.

## Key Environment Variables

The environment variables are only used for Textract OCR.
If using Tesseract, none are needed.

- `AWS_ACCESS_KEY_ID`: AWS access key for Textract (optional)
- `AWS_SECRET_ACCESS_KEY`: AWS secret key for Textract (optional)
- `AWS_REGION`: AWS region for Textract (default: `ca-central-1`)
- `AWS_S3_BUCKET`: S3 bucket for async Textract jobs (default: `text-extraction-temp`)

## Error Handling

Both `/extract` and `/refresh-topics` return HTTP 500 with the error message when processing fails.

## Test Coverage
The `tests/` folder for this service contains tests for the various file handlers and postprocessor:
- **Default Material Handling** with topic matching
- **Slides Handling** with font-based extraction
- **Article Handling** with font-based extraction and soft assertion for BERTopic extraction
- **Postprocessing** with various patterns and edge cases

AWS Textract OCR isn't covered in the tests, as it is not available in LocalStack and costs credits to use.