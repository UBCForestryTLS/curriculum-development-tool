# FastAPI LO Mapping Service

## Overview

The Learning Outcome Mapping Service is a FastAPI application that maps CLOs to PLOs or standards using an LLM (Qwen/Qwen3-8B).

This service involves AWS infrastructure, persisted request state, background processing, and callbacks to Laravel tool.

## Primary Responsibility

This service is responsible for:

- accepting outcome mapping requests
- converting request data into batch transform input records
- storing job state in DynamoDB
- storing input in S3
- invoking Lambda functions to start processing
- extracting output from S3 and post-processing model output into structured mapping results
- notifying Laravel tool when results are ready

## API

### `GET /health`

Returns a simple response:

```json
{
  "status": "ok"
}
```

### `POST /map-program-outcomes`

This is the main intake endpoint for new mapping requests.

It accepts an [`OutcomeMappingRequest`](../python/services/lo_mapping_service/app/schemas.py) with:

- `course_id`
- `program_id`
- `course_learning_outcomes`
- `program_learning_outcomes`
- `mapping_scales`

Example request:

```json
{
  "course_id": 101,
  "program_id": 202,
  "course_learning_outcomes": [
    {
      "l_outcome_id": 1,
      "l_outcome": "Apply forestry principles"
    }
  ],
  "program_learning_outcomes": [
    {
      "pl_outcome_id": 2,
      "pl_outcome": "Demonstrate strong understanding of designing tools and competence"
    }
  ],
  "mapping_scales": [
    {
      "map_scale_id": 1,
      "title": "Advanced",
      "abbreviation": "A",
      "description": "Advanced Alignment"
    }
  ]
}
```

Successful responses include:

- a message from the Lambda start step
- the created batch job name
- the request record identifier of DynamoDB

### `POST /get-processed-results`

This endpoint lets Laravel tool manually request the mapping result for a specific `course_id` and `program_id` for demand-based access to results.

It:

- looks up the latest matching DynamoDB record in `AWAITING_COMPLETION` or `AWAITING_COMPLETION_FAILED`
- processes it if available
- otherwise returns a `pending` response

This acts as a catch-up mechanism when Laravel tool wants to access the results before they are automatically stored by the scheduled job.

### `POST /process-batch-transform-results`

**Note: This would no longer be required with scheduled job and demand-based access of mapping results**

This endpoint accepts a payload containing `recordsAwaitingProcessing` and schedules background post-processing using FastAPI `BackgroundTasks`.

It exists so the Lambda function does not need to block while full post-processing runs.

## End-To-End Lifecycle

The normal job lifecycle is:

1. A client requests for mapping results via AI suggests on the Laravel tool
2. The tool creates a request with course id, program id, course outcomes, program outcomes, and mapping scales to `POST /map-program-outcomes`.
3. `BatchTransformInputBuilder` creates batch transform input records and writes the generated input in S3.
4. The service creates a DynamoDB request record with metadata such as `request_id`, `course_id`, `program_id`, `status`, `input_s3_path`, and `output_s3_path`.
5. The service synchronously invokes the `start-batch-transform-job` Lambda.
6. AWS processing eventually produces output in S3.
7. Eventbridge is triggered when Sagemaker batch transform job status changes to `COMPLETE` or `FAILED`.
8. Result-processing lambda function changes the status of corresponding request in DynamoDB and triggers `start-batch-transform-job` Lambda for any requests in `PENDING` status in DynamoDB.
9. A scheduled job or demand-based request from Laravel tool causes `process_records()` to run.
10. The service reads JSONL output from S3, parses mapping results, and converts them into structured result.
11. The service posts those results to the Laravel URL.
12. The DynamoDB request record is deleted after successful completion.


## Request Record Model

The DynamoDB request store is implemented in [`LOMappingRequestDynamoDBRecord`](../python/services/lo_mapping_service/app/services/lo_mapping_request_dynamo_db_request.py).

Important record fields include:

- `request_id`
- `course_id`
- `program_id`
- `status`
- `input_s3_path`
- `output_s3_path`
- `created_at`

The service also ensures the DynamoDB table exists during FastAPI startup and uses a global secondary index on:

- `status`
- `created_at`

This index supports querying records by status and creation time.

## Result Processing Behavior

Post-processing is handled in [`process_batch_transform_results.py`](../python/services/lo_mapping_service/app/services/process_batch_transform_results.py).

The processing logic:

- reads JSONL output from S3
- extracts `generated_text`
- strips any `<think> ... </think>` tags and any generated text before `</think>`
- parses JSON result payloads, with line-based fallback
- extracts composite identifiers of the form `clo-{id}__plo-{id}`
- returns normalized results containing `clo_id`, `plo_id`, `is_mapped`, `explanation`, and `map_labels`

If a record is marked `AWAITING_COMPLETION_FAILED`, the service notifies Laravel with an empty result set instead of reading S3 output.

## Sends Processed Results To Laravel Tool

The service posts processed results to the URL configured by `LARAVEL_API_URL`.

The callback payload contains:

- `request_id`
- `course_id`
- `program_id`
- `status`
- `results`

## Runtime Dependencies

This service depends on several external systems:

- DynamoDB for request metadata and workflow status
- S3 for batch input and output storage
- Lambda for starting batch and post-processing after job completion or failure

### Key Environment Variables

- `ALLOWED_ORIGINS`
- `LO_MAPPING_DYNAMODB_REQUESTS_TABLE`
- `AWS_REGION`
- `ACCESS_KEY`
- `SECRET_KEY`
- `DYNAMODB_STATUS_INDEX`
- `LARAVEL_API_URL`

These settings control CORS, DynamoDB table access, AWS clients, and the Laravel tool target.

## Startup And Background Behavior

This service has startup behavior:

- on startup, it ensures the DynamoDB table exists
- it creates and starts an APScheduler instance
- on shutdown, it stops the scheduler

## Error Handling Notes

The service raises HTTP 500 responses when mapping request start fails, including failures during:

- input preparation
- request record creation
- Lambda invocation

During result post-processing, failures are logged and skipped per record so one bad record does not stop processing of the rest.

## Test Coverage

The service includes tests for several layers of behavior:

- **Batch transform input generation**: [`python/services/lo_mapping_service/tests/test_batch_transform_input_builder.py`](../python/services/lo_mapping_service/tests/test_batch_transform_input_builder.py)
- **DynamoDB request:**  [`python/services/lo_mapping_service/tests/test_mapping_request_repository.py`](../python/services/lo_mapping_service/tests/test_mapping_request_repository.py)
- **Lambda handlers:**
  - [`python/services/lo_mapping_service/tests/test_lambda_handler_start_batch_tranform_job.py`](../python/services/lo_mapping_service/tests/test_lambda_handler_start_batch_tranform_job.py)
  - [`python/services/lo_mapping_service/tests/test_lambda_handler_process_batch_transform_inference_results.py`](../python/services/lo_mapping_service/tests/test_lambda_handler_process_batch_transform_inference_results.py)
- **Result post-processing:** [`python/services/lo_mapping_service/tests/test_process_batch_transform_results.py`](../python/services/lo_mapping_service/tests/test_process_batch_transform_results.py)
- **LocalStack end-to-end testing (TBD)**