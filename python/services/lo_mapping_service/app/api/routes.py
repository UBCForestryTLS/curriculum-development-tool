from contextlib import asynccontextmanager

from fastapi import FastAPI, HTTPException, BackgroundTasks
from fastapi.middleware.cors import CORSMiddleware

from app.core.config import settings
from app.core.logging_config import logger
import json
import os


from app.schemas import (
    OutcomeMappingRequest,
    CourseProgramPair,
    InFlightStatusRequest,
)
from app.services import BatchTransformInputBuilder, LOMappingRequestDynamoDBRecord, process_batch_transform_results
from app.services.scheduled_job import create_scheduler
from app.services.process_batch_transform_results import process_records


lo_mapping_request_store = LOMappingRequestDynamoDBRecord()
boto_session = lo_mapping_request_store._create_boto_session()
REGION = os.environ.get("AWS_REGION", "ca-central-1")
lambda_client = boto_session.client("lambda", region_name=REGION)


@asynccontextmanager
async def lifespan(app: FastAPI):
    try:
        lo_mapping_request_store.ensure_table_exists()
        logger.info("DynamoDB table ready")
    except Exception as e:
        logger.error(f"Error during application startup: {e}")
        raise e
    scheduler = create_scheduler()
    scheduler.start()
    logger.info("APScheduler started.")
    app.state.scheduler = scheduler

    yield

    scheduler.shutdown(wait=False)
    logger.info("APScheduler stopped.")


app = FastAPI(
    title="Learning Outcome Mapping Service",
    version="0.1.0",
    docs_url="/docs",
    redoc_url="/redoc",
    lifespan=lifespan,
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


@app.post("/map-program-outcomes")
async def map_program_outcomes(request: OutcomeMappingRequest)-> dict:
    logger.info("/map-program-outcomes called for course_id=%s program_id=%s", request.course_id, request.program_id)
    try:
        logger.info("Step 1/4: checking for existing in-flight record")
        existing = lo_mapping_request_store.find_in_flight_records_for_pairs(
            [(request.course_id, request.program_id)]
        ).get((request.course_id, request.program_id))
        if existing is not None:
            logger.info(
                "Dedupe: existing in-flight record found for course_id=%s program_id=%s status=%s — reusing.",
                request.course_id, request.program_id, existing.get("status"),
            )
            return {
                "message": "Existing request in flight for this course/program, reusing it.",
                "jobName": existing.get("transform_job_name"),
                "startedForRecordId": existing.get("request_id"),
                "deduplicated": True,
            }

        logger.info("Step 2/4: building batch prompt records (uploads to S3)")
        batchTranformInputBuilder = BatchTransformInputBuilder(request)
        s3_input_path = batchTranformInputBuilder.build_batch_prompt_records()
        logger.info("Step 2/4 done: s3_input_path=%s", s3_input_path)

        logger.info("Step 3/4: writing PENDING record to DynamoDB table=%s", lo_mapping_request_store.table_name)
        record = lo_mapping_request_store.create_request(
            course_id=request.course_id,
            program_id=request.program_id,
            input_s3_path=s3_input_path,
            status="PENDING",
        )
        logger.info("Step 3/4 done: created record_id=%s", record["request_id"])

        logger.info("Step 4/4: invoking start-batch-transform-job Lambda")
        try:
            response = lambda_client.invoke(
                FunctionName="start-batch-transform-job",
                InvocationType="RequestResponse",
                Payload=json.dumps({"record_id": record["request_id"]
                                    }).encode("utf-8")
            )

            if response["StatusCode"] != 200:
                raise HTTPException(status_code=500, detail="Lambda invocation failed")

            result = json.loads(response["Payload"].read())
            body = result.get("body", {}) if isinstance(result, dict) else {}

            logger.info("Step 4/4 done: lambda returned %s", body)
            return {
                "message": body.get("message", "Submitted"),
                "jobName": body.get("jobName") or body.get("existingJob"),
                "startedForRecordId": body.get("startedForRecordId") or record["request_id"],
            }

        except Exception as e:
            logger.exception("Step 4/4 failed: lambda invocation error (PENDING record %s is in DynamoDB)", record["request_id"])
            raise HTTPException(status_code=500, detail=str(e))
    except HTTPException:
        raise
    except Exception as e:
        logger.exception("Error in mapping LOs (full traceback above)")
        raise HTTPException(status_code=500, detail="Something went wrong while processing mapping request")
    
@app.post("/in-flight-status")
async def in_flight_status(request: InFlightStatusRequest) -> dict:
    """
    For each (course_id, program_id) pair in the request, return whether an
    in-flight DynamoDB record exists (any status not yet delivered to Laravel
    and deleted).

    Used by Laravel both at Step 5 page render time (to render the right state
    for each program) and right before submitting a new request (race-safe pre-check).
    """
    pairs = [(p.course_id, p.program_id) for p in request.pairs]
    records_by_pair = lo_mapping_request_store.find_in_flight_records_for_pairs(pairs)

    statuses = []
    for pair in pairs:
        record = records_by_pair.get(pair)
        if record is None:
            statuses.append({
                "course_id": pair[0],
                "program_id": pair[1],
                "in_flight": False,
            })
        else:
            statuses.append({
                "course_id": pair[0],
                "program_id": pair[1],
                "in_flight": True,
                "status": record.get("status"),
                "request_id": record.get("request_id"),
                "created_at": record.get("created_at"),
            })
    logger.info("In-flight status check: pairs=%s statuses=%s", pairs, statuses)
    return {"statuses": statuses}


@app.post("/process-batch-transform-results")
async def process_batch_transform_results_endpoint(request: dict, background_tasks: BackgroundTasks) -> dict:
    # To ensure lambda_handler_process_batch_transform_inference_results does not wait for it to complete
    background_tasks.add_task(process_batch_transform_results.process_records, request["recordsAwaitingProcessing"])
    return {"message": "accepted"}

@app.post("/poll-results-status")
async def poll_results_status(body: CourseProgramPair):
    """Read-only check: is there an AWAITING_COMPLETION record ready to process?"""
    record = lo_mapping_request_store.find_latest_awaiting_record_by_ids(
        body.course_id, body.program_id
    )
    if not record:
        return {"status": "pending"}
    return {
        "status":     "ready_to_process",
        "record_id":  record.get("request_id"),
        "record_status": record.get("status"),
    }


@app.post("/process-pending-results")
async def process_pending_results(body: CourseProgramPair, background_tasks: BackgroundTasks):
    """
    Trigger S3 read + Laravel delivery + DynamoDB cleanup as a background task,
    and return immediately.
    """
    logger.info(
        "Process pending results request — course_id=%s program_id=%s",
        body.course_id, body.program_id,
    )

    record = lo_mapping_request_store.find_latest_awaiting_record_by_ids(
        body.course_id, body.program_id
    )
    if not record:
        return {
            "status":  "pending",
            "message": "No record ready to process for this course/program.",
        }

    background_tasks.add_task(process_records, [record])
    return {
        "status":    "processing_triggered",
        "message":   "Processing started in background.",
        "record_id": record.get("request_id"),
    }


