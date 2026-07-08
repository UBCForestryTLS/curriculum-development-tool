import os

from apscheduler.schedulers.asyncio import AsyncIOScheduler
from apscheduler.triggers.interval import IntervalTrigger

from app.core.logging_config import logger
from app.services.lo_mapping_request_dynamo_db_request import LOMappingRequestDynamoDBRecord
from app.services.process_batch_transform_results import process_records


POLL_INTERVAL_HOURS = float(os.environ.get("POLL_INTERVAL_HOURS", 6))
STATUSES_TO_PROCESS = ("AWAITING_COMPLETION", "AWAITING_COMPLETION_FAILED")

request_store = LOMappingRequestDynamoDBRecord()


async def run_post_processing() -> None:
    """
    Fetch AWAITING_COMPLETION and AWAITING_COMPLETION_FAILED records
    from DynamoDB and the post-process
    """
    logger.info("Scheduled post-processing job started.")

    all_records: list[dict] = []

    for status in STATUSES_TO_PROCESS:
        try:
            records = request_store.get_records_by_status(status)
            logger.info("Found %d record(s) with status '%s'.", len(records), status)
            all_records.extend(records)
        except Exception as e:
            logger.error("Failed to fetch records for status '%s': %s", status, e)

    if all_records:
        await process_records(all_records)
    else:
        logger.info("No records to process.")

    logger.info("Scheduled post-processing job finished.")


def create_scheduler() -> AsyncIOScheduler:
    scheduler = AsyncIOScheduler()
    scheduler.add_job(
        run_post_processing,
        trigger=IntervalTrigger(hours=POLL_INTERVAL_HOURS),
        id="post_processing_job",
        name="Process awaiting LO mapping records",
        replace_existing=True,
        max_instances=1,
        misfire_grace_time=60,
    )
    return scheduler
