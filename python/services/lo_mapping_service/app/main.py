import argparse
import os

import uvicorn


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Run the lo_mapping_service FastAPI app.")
    parser.add_argument(
        "--poll-interval-hours",
        type=float,
        default=None,
        help="Override the scheduler poll interval in hours (e.g. 0.10 for testing). "
             "Default: 6 (or whatever POLL_INTERVAL_HOURS env var is set to).",
    )
    args = parser.parse_args()

    if args.poll_interval_hours is not None:
        os.environ["POLL_INTERVAL_HOURS"] = str(args.poll_interval_hours)

    config = uvicorn.Config("app.api.routes:app", host="127.0.0.1", port=8002, reload=True)
    server = uvicorn.Server(config)
    server.run()
