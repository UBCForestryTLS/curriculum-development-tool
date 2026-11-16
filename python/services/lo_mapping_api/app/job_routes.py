from uuid import UUID

from botocore.exceptions import BotoCoreError, ClientError
from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import ValidationError

from app.auth import require_api_key
from app.job_models import MappingJobRequest, MappingJobResponse
from app.job_store import MappingJobConflictError, MappingJobStore


# Every job route needs the API key headers; /health stays public.
router = APIRouter(prefix="/jobs", tags=["jobs"], dependencies=[Depends(require_api_key)])


@router.post("", response_model=MappingJobResponse)
def create_job(request: MappingJobRequest) -> dict[str, object]:
    """Save the job and its pairs without starting inference."""
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
    """Return the saved job's status and progress."""
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
