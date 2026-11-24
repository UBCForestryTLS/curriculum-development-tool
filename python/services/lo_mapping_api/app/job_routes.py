from typing import Annotated
from uuid import UUID

from botocore.exceptions import BotoCoreError, ClientError
from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import ValidationError

from app.auth import require_api_key
from app.batch_runner import MappingJobNotFoundError, MappingJobNotReadyError, run_batch
from app.job_models import MappingJobRequest, MappingJobResponse, MappingJobResultsResponse
from app.job_store import InvalidResultsCursorError, MappingJobConflictError, MappingJobStore


# Every job route needs the API key headers; /health stays public.
router = APIRouter(prefix="/jobs", tags=["jobs"], dependencies=[Depends(require_api_key)])


@router.post("", response_model=MappingJobResponse)
def create_job(request: MappingJobRequest) -> dict[str, object]:
    """Save the job and all CLO-PLO pairs from the supplied outcomes, then return saved progress.

    Model calls start separately through the run route.
    """
    try:
        # Repeating the same request returns the saved job without resetting its progress.
        return MappingJobStore().create(request)
    except MappingJobConflictError:
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail="Job ID already exists with different inputs.",
        ) from None
    except (ValidationError, BotoCoreError, ClientError):
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="Job storage is unavailable.",
        ) from None


@router.get("/{job_id}", response_model=MappingJobResponse)
def get_job(job_id: UUID) -> dict[str, object]:
    """Return the saved job summary, including its status and counts, so Laravel can check progress.

    This only reads progress; it does not process pairs or return their individual results.
    """
    try:
        job = MappingJobStore().get(job_id)
    except (ValidationError, BotoCoreError, ClientError):
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="Job storage is unavailable.",
        ) from None

    if job is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Mapping job not found.",
        )
    return job


@router.get("/{job_id}/results", response_model=MappingJobResultsResponse)
def get_job_results(
    job_id: UUID,
    limit: Annotated[int, Query(ge=1, le=100, description="Maximum pairs read before filtering.")] = 50,
    cursor: Annotated[str | None, Query(min_length=1, max_length=1024)] = None,
) -> dict[str, object]:
    """Return a page of saved outcomes and progress for a finished or unfinished job.

    Follow next_cursor even on empty pages. For later refreshes, start from the first
    page: an earlier pending pair may now have an outcome. Laravel should save each
    job/pair once and do a final read from the beginning after the job finishes.
    """
    try:
        store = MappingJobStore()
        job = store.get(job_id)
        if job is None:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail="Mapping job not found.",
            )
        if not job["pairs_ready"]:
            raise HTTPException(
                status_code=status.HTTP_409_CONFLICT,
                detail="Mapping job is not ready for results.",
            )
        # These reads are not a snapshot together. Progress may advance while pages are read.
        return {**job, **store.get_results_page(job_id, limit=limit, cursor=cursor)}
    except InvalidResultsCursorError:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_CONTENT,
            detail="Invalid results cursor for this job.",
        ) from None
    except (ValidationError, BotoCoreError, ClientError):
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="Job storage is unavailable.",
        ) from None


@router.post("/{job_id}/run", response_model=MappingJobResponse)
def run_job_batch(job_id: UUID) -> dict[str, object]:
    """Process one batch of pending CLO-PLO pairs and return the job's saved status and counts.

    Laravel waits for this response before requesting another batch for same job
    """
    try:
        # Wait for this batch to finish before Laravel requests the next one for the same job.
        # The runner picks pending pairs and applies the batch size, concurrency and time limits.
        return run_batch(job_id)
    except MappingJobNotFoundError:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Mapping job not found.",
        ) from None
    except MappingJobNotReadyError:
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail="Mapping job is not ready for processing.",
        ) from None
    except (ValidationError, BotoCoreError, ClientError):
        # Results already saved stay recorded. Laravel can check progress before trying again.
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="Job processing is unavailable.",
        ) from None
