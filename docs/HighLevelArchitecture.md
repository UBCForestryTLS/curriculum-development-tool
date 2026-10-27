# High-Level Architecture

## Overview

This application is split into a primary Laravel web application and a set of Python services:

- `laravel/`: the main user interface of the Curriculum Mapping Tool with courses, programs, syllabi, persistence, permissions, and reporting
- `python/services/syllabi_service/`: a FastAPI service that parses uploaded syllabus files and extracts structured course data
- `python/services/lo_mapping_service/`: a FastAPI service that prepares and manages learning-outcome mapping via LLM (Qwen/Qwen3-8B) and post-processes the results
- `python/services/text_extraction_service/`: a FastAPI service that extracts text from PDF course materials (with Tesseract OCR if it's a scanned PDF) and also extracts likely topics based on keyword extraction and font properties

![Architecture Diagram](./images/ArchitectureDiagram.png)

## Laravel Application

The Laravel application is the core platform. It is responsible for:

- authentication, authorization, and role-based access control
- course, program, syllabus, and outcome management
- admin tooling and dashboard workflows
- document generation and reporting
- queue-based background work
- persistence of entities in the relational database (PostgreSQL)

Important areas in the codebase include:

- `laravel/app/Http/Controllers/`: request handling and user workflows
- `laravel/app/Models/`: domain models such as courses, programs, outcomes, mappings, syllabi, and users
- `laravel/app/Jobs/`: background jobs, including syllabus-file processing
- `laravel/routes/web.php`: the main browser-facing routes

For a detailed explanation of how access control works in the tool, see [Role-Based Access Management](./role-based-access-management.md)

## Python Services

### Syllabi Service

`python/services/syllabi_service/` is a FastAPI application that exposes `POST /create_course_from_syllabi`.

Its responsibility is to take a syllabus file path plus the original filename, parse the document, and return structured course data such as:

- course code and number
- title, term, and year
- description
- learning outcomes
- assessment methods and weights

The parsing logic lives in `app/services/syllabus_parser.py` and uses document/text extraction libraries to convert uploaded syllabus files into structured JSON.

This service behaves as a processing service that returns extracted data back to the tool.

For service-specific notes, see [FastAPI Syllabi Service](./FastAPISyllabiService.md)

### Learning Outcome Mapping Service

`python/services/lo_mapping_service/` is a FastAPI application for asynchronous CLO-to-PLO or standards mapping.

Its responsibilities are to:

- accept mapping requests through `POST /map-program-outcomes`
- generate batch transform input records from course outcomes, program outcomes, and mapping scales
- persist request state in DynamoDB with processing status
- store batch input/output references in S3
- invoke Lambda functions to start and monitor batch inference jobs
- post-process model output into structured mapping results
- send the final results to the tool HTTP endpoint configured by `LARAVEL_API_URL`

It also provides:

- `GET /health` for service health checks
- `POST /in-flight-status` to check existing requests for course/program pairs
- `POST /poll-results-status` to check whether results are ready to process
- `POST /process-pending-results` to process ready results in the background
- `POST /process-batch-transform-results` to process an explicitly supplied list of records in the background


For service-specific notes, see [FastAPI LO Mapping Service](./FastAPILOMappingService.md)

## How The Services Interact

### 1. Syllabus Import Flow

1. A user uploads a syllabus file to the tool.
2. Laravel tool stores metadata for the uploaded file in `CourseSyllabiFile`.
3. Laravel tool dispatches `ProcessCourseSyllabiFile`.
4. The job calls the Python syllabi service using `config('services.python_api.base_url')` and `POST /create_course_from_syllabi`.
5. The Python service parses the file and returns structured course information.
6. Laravel tool creates and persists the resulting `Course`, `CourseDescription`, `LearningOutcome`, `AssessmentMethod`, and ownership records.

In this flow, communication is synchronous HTTP from the Laravel queue job to FastAPI.

### 2. Learning Outcome Mapping Flow

1. User submits a mapping request in the Laravel Tool
2. A request is sent to Python `POST /map-program-outcomes` whose payload contains course id, program id, course outcomes, program outcomes, and mapping scales.
3. The service reuses an existing in-flight request for the course/program pair, or creates batch transform input records and uploads them to S3.
4. For a new request, it creates a DynamoDB record whose status progresses through values such as `PENDING`, `IN_PROGRESS`, `AWAITING_COMPLETION`, or `AWAITING_COMPLETION_FAILED`.
5. A Lambda function starts the batch transform job for the oldest job in `PENDING` status stored in DynamoDB if no job is running, otherwise does nothing.
6. When inference completes, fails, or stops, the EventBridge-triggered Lambda updates the corresponding job's status in DynamoDB and invokes the start-job Lambda if a pending request exists.
7. A scheduled job runs `process_records` in FastAPI. Laravel can also check `/poll-results-status` and call `/process-pending-results` when results are ready.
8. The output is read from S3 and converted into structured mapping results such as `clo_id`, `plo_id`, `is_mapped`, `map_labels`, and `explanation`.
9. The mapping service sends those processed results to a Laravel endpoint defined by `LARAVEL_API_URL`.

The integration has [browser tests using LocalStack](../laravel/tests/Browser/AiSuggestionsE2E/README.md). These cover the UI and result-delivery workflow, with SageMaker inference simulated; they do not verify live AWS inference.
