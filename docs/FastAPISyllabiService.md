# FastAPI Syllabi Service

## Overview

The syllabi service is a FastAPI service dedicated to parsing uploaded syllabus documents into structured course data that Laravel tool can store.

The service's role is to accept a file reference, inspect the document, extract course details, and return normalized JSON.

This service is implemented primarily under:

- [`python/services/syllabi_service/app/api/routes.py`](../python/services/syllabi_service/app/api/routes.py)
- [`python/services/syllabi_service/app/services/syllabus_parser.py`](../python/services/syllabi_service/app/services/syllabus_parser.py)

## Primary Responsibility

The service is responsible for extracting:

- course code
- course number
- course title
- term and year of offering
- course level (undergraduate/graduate)
- course description
- learning outcomes
- assessment methods along with their corresponding weights
- course topics
- course materials with name, type, and description

## API

### `POST /create_course_from_syllabi`

This is the only endpoint exposed by the service.

It accepts a JSON body shaped by [`CourseSyllabiFile`](../python/services/syllabi_service/app/schema/courseSyllabi.py):

```json
{
  "file_path": "/absolute/or/relative/path/to/file.pdf",
  "client_original_filename": "FRST101_2025W1_Syllabus.pdf"
}
```

The response shape is:

```json
{
  "status": "success",
  "data": {
    "code": "FRST",
    "number": 101,
    "title": "Introduction to Forestry",
    "term": "W1",
    "year": 2025,
    "level": "Undergraduate",
    "description": "...",
    "goals": ["..."],
    "assessments": [["Midterm", 30], ["Final Exam", 40]],
    "topics": ["Forest policy", "Harvest planning"],
    "materials": [
      {
        "name": "Introduction to Forestry",
        "type": "textbook",
        "description": "Required textbook"
      }
    ]
  },
  "message": "Course created successfully"
}
```

When parsing fails, the route logs the underlying exception and returns:

```json
{
  "status": "error",
  "message": "An error occurred while processing the request."
}
```

## Request Flow

The processing flow is:

1. Laravel tool sends a file path and original filename.
2. FastAPI validates the request body with Pydantic.
3. `syllabus_parser.get_course_from_text_file()` opens the referenced document.
4. The parser extracts course metadata from both filename patterns and document text.
5. The parser returns a resulting object.
6. The API wraps that result in a success envelope and returns it to the caller.

This service is synchronous and request/response oriented.

## Parsing Strategy

The implementation in [`syllabus_parser.py`](../python/services/syllabi_service/app/services/syllabus_parser.py), combines the following:

- PDF and document text extraction with `pymupdf`
- filename pattern matching for course code and term
- text scanning with regular expressions
- header and footer removal before downstream parsing
- table detection for structured fields such as course title, assessments etc.
- section-based extraction for descriptions, learning outcomes, assessments, topics, and materials
- topic extraction from schedule/topic tables first, with text-based extraction as a fallback
- material extraction from materials/readings/textbook sections, including type inference for resources such as textbooks, readings, articles, videos, and websites

The parser is designed to accept multiple syllabus layouts rather than requiring a single file format.

## Important Defaults

- if no course code is found, the parser falls back to `"TEST"`
- if no course number is found, the parser falls back to `999`
- if the year cannot be parsed, the parser falls back to the current year
- the original filename is checked first because it can disambiguate the course more reliably than free text
- learning outcomes are detected using a list of likely action verbs and bullet patterns
- the current Laravel upload flow only accepts PDF syllabi, so the main supported extraction path is PDF parsing

These defaults make the parser resilient, but they also mean that Laravel tool user should treat results as as suggestions rather than absolute truth.

## Supported Inputs

The the service is intended to handle:

- PDF syllabi
- files with tabular assessment sections
- files with non-tabular assessment sections
- files with topic schedules or topic lists
- files with course materials, readings, textbook, or resource sections
- filenames that do or do not contain course identifiers

Representative examples live under:

- [`python/services/syllabi_service/tests/data/syllabi/`](../python/services/syllabi_service/tests/data/syllabi)

### Environment Variables

- `ALLOWED_ORIGINS`: comma-separated origins for CORS

At runtime, the FastAPI app enables permissive HTTP methods and headers, while limiting browser origins through this setting

## Logging And Error Handling

Errors in the route are logged through the service logging configuration and converted into a generic error response.

That means:

- clients receive a stable response
- detailed parsing failures are available in logs rather than returned directly
- invalid requests are still rejected by FastAPI validation

This behavior is covered by API tests in:

- [`python/services/syllabi_service/tests/test_api.py`](../python/services/syllabi_service/tests/test_api.py)

## Test Coverage

The service includes both API-level and parser-level tests:

- [`python/services/syllabi_service/tests/test_api.py`](../python/services/syllabi_service/tests/test_api.py)
- [`python/services/syllabi_service/tests/test_syllabus_parser.py`](../python/services/syllabi_service/tests/test_syllabus_parser.py)

These tests are valuable because the service relies heavily on heuristics and document-layout edge cases.

## Operational Notes

Few constraints that might be important to consider for this service:

- it depends on the referenced file path being readable from the service runtime
- parsing quality depends on document text quality and layout clarity
- topic and material extraction is heuristic, so noisy schedule/material sections may still need user review
- because the service returns structured suggestions, review or correction in Laravel tool is still important
