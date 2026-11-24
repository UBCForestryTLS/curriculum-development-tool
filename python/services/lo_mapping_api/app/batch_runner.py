from concurrent.futures import FIRST_COMPLETED, ThreadPoolExecutor, wait
from time import monotonic
from uuid import UUID

from botocore.config import Config

from app.bedrock_client import BedrockClient, BedrockResponseError
from app.config import BatchSettings, get_batch_settings
from app.job_store import MappingJobStore
from app.mapping_response import MappingResponseError
from app.mapping_service import map_pair


# Start with a 20 second budget to leave room below the gateway's 30 second timeout
# new calls are stopped after 8 seconds to leave time for the last calls to finish and have their outcomes saved
# Storage and other overhead can take longer so this does not guarantee a total duration
# this will be checked again when testing the complete service flow.
BATCH_TIME_BUDGET_SECONDS = 20
CONNECT_TIMEOUT_SECONDS = 2
MODEL_READ_TIMEOUT_SECONDS = 8
SAVE_RESERVE_SECONDS = 2


class MappingJobNotFoundError(ValueError):
    """There is no saved job to process."""


class MappingJobNotReadyError(ValueError):
    """The job's pairs have not all been saved yet."""


def run_batch(
    job_id: UUID | str,
    *,
    store: MappingJobStore | None = None,
    bedrock_client: BedrockClient | None = None,
    settings: BatchSettings | None = None,
) -> dict[str, object]:
    """Process one limited batch and return saved progress. Call batches sequentially per job."""
    # Start timing before setup so fetching the job also uses part of the budget.
    # monotonic() keeps measuring elapsed time even if the system clock is adjusted.
    deadline = monotonic() + BATCH_TIME_BUDGET_SECONDS
    settings = settings or get_batch_settings()
    if store is None:
        store = MappingJobStore(
            config=Config(
                connect_timeout=CONNECT_TIMEOUT_SECONDS,
                read_timeout=3,
                retries={"mode": "standard", "total_max_attempts": 1},
            )
        )
    job = store.get(job_id)
    if job is None:
        raise MappingJobNotFoundError("Mapping job not found.")
    if not job["pairs_ready"]:
        raise MappingJobNotReadyError("Mapping job is not ready for processing.")
    if job["status"] not in ("pending", "in_progress"):
        return job
    pairs = store.get_pending_pairs(job_id, limit=settings.batch_size)
    if not pairs:
        return store.finish_if_complete(job_id)

    # With these defaults, stop starting calls 8 seconds after run_batch begins:
    # 20 seconds - 2 for connecting - 8 for reading the response - 2 for saving.
    # This leaves time for the last calls to finish and have their outcomes saved.
    stop_starting_at = deadline - CONNECT_TIMEOUT_SECONDS - MODEL_READ_TIMEOUT_SECONDS - SAVE_RESERVE_SECONDS
    if monotonic() >= stop_starting_at:
        return store.finish_if_complete(job_id)

    # DynamoDB returns numbers as Decimal; the prompt needs normal integer IDs for JSON.
    mapping_scales = [
        {**scale, "map_scale_id": int(scale["map_scale_id"])}
        for scale in job["mapping_scale_options"]
    ]
    level_ids = {scale["abbreviation"]: scale["map_scale_id"] for scale in mapping_scales}
    if bedrock_client is None:
        bedrock_client = BedrockClient(
            config=Config(
                connect_timeout=CONNECT_TIMEOUT_SECONDS,
                read_timeout=MODEL_READ_TIMEOUT_SECONDS,
                max_pool_connections=settings.concurrency,
            )
        )

    def map_pending_pair(pair):
        # Check again in the worker in case starting the thread was delayed.
        if monotonic() >= stop_starting_at:
            return None
        return map_pair(
            clo_id=int(pair["clo_id"]),
            clo_text=pair["clo_text"],
            plo_id=int(pair["plo_id"]),
            plo_text=pair["plo_text"],
            mapping_scales=mapping_scales,
            bedrock_client=bedrock_client,
        )

    remaining = iter(pairs)
    in_flight = {}
    failure = None
    with ThreadPoolExecutor(max_workers=min(settings.concurrency, len(pairs))) as executor:
        while True:
            # Queue only active work, so unused pairs stay pending when the budget runs out.
            while (
                failure is None
                and len(in_flight) < settings.concurrency
                and monotonic() < stop_starting_at
            ):
                pair = next(remaining, None)
                if pair is None:
                    break
                in_flight[executor.submit(map_pending_pair, pair)] = pair
            if not in_flight:
                break

            # Passing the cutoff doesn't cancel calls already sent; collect and save their outcomes.
            # Pairs we haven't started stay pending for the next batch.
            completed, _ = wait(in_flight, return_when=FIRST_COMPLETED)
            for future in completed:
                pair = in_flight.pop(future)
                try:
                    # Keep DynamoDB writes on this thread; only model calls run concurrently.
                    _save_outcome(store, job_id, pair, future, level_ids)
                except Exception as error:
                    # Stop submitting, but collect other calls already sent before raising the failure.
                    if failure is None:
                        failure = error

    if failure is not None:
        raise failure
    return store.finish_if_complete(job_id)


def _save_outcome(store, job_id, pair, future, level_ids) -> None:
    """Save a validated answer or model-response error; let AWS and storage failures reach the caller."""
    try:
        result = future.result()
    except (BedrockResponseError, MappingResponseError) as error:
        store.save_pair_error(job_id, pair["clo_id"], pair["plo_id"], error=str(error))
        return
    if result is None:
        return
    # The model returns labels such as APPLY; stored results use the original mapping-scale IDs.
    store.save_pair_result(
        job_id,
        pair["clo_id"],
        pair["plo_id"],
        mapping_scale_ids=[level_ids[label] for label in result["map_labels"]],
        explanation=result["explanation"],
    )
