"""Preview with python benchmark_mapping.py from the mapping API directory.

Live calls require --run --output <new.csv>; use --profile to select your AWS login.
"""

import argparse
import csv
import json
import sys
from concurrent.futures import FIRST_COMPLETED, ThreadPoolExecutor, wait
from contextlib import closing
from datetime import UTC, datetime
from itertools import islice
from pathlib import Path
from statistics import median
from time import perf_counter

import boto3
from botocore.config import Config
from botocore.exceptions import BotoCoreError, ClientError
from pydantic import BaseModel, ConfigDict, Field, TypeAdapter

from app.bedrock_client import BedrockClient, BedrockResponseError
from app.config import get_bedrock_settings
from app.job_models import (
    CourseLearningOutcome,
    MappingScaleInput,
    NonBlankText,
    ProgramLearningOutcome,
)
from app.mapping_prompt import build_mapping_prompt
from app.mapping_service import map_pair


class BenchmarkScenario(BaseModel):
    """Use the service's outcome fields without needing a job or database."""

    model_config = ConfigDict(extra="forbid")
    name: NonBlankText
    course_learning_outcomes: list[CourseLearningOutcome] = Field(min_length=1)
    program_learning_outcomes: list[ProgramLearningOutcome] = Field(min_length=1)
    mapping_scales: list[MappingScaleInput] = Field(min_length=1)


def load_pairs(path: Path) -> list[tuple[str, dict]]:
    """Validate all scenarios and prompts before any paid calls can start."""
    scenarios = TypeAdapter(list[BenchmarkScenario]).validate_json(path.read_text(encoding="utf-8"))
    if not scenarios or len({scenario.name for scenario in scenarios}) != len(scenarios):
        raise ValueError("Provide at least one scenario, with a unique name for each.")

    pairs = []
    for scenario in scenarios:
        for items, id_field in (
            (scenario.course_learning_outcomes, "l_outcome_id"),
            (scenario.program_learning_outcomes, "pl_outcome_id"),
            (scenario.mapping_scales, "map_scale_id"),
        ):
            if len({getattr(item, id_field) for item in items}) != len(items):
                raise ValueError(f"{scenario.name}: {id_field} values must be unique.")

        scales = [scale.model_dump() for scale in scenario.mapping_scales]
        for clo in scenario.course_learning_outcomes:
            for plo in scenario.program_learning_outcomes:
                inputs = {
                    "clo_id": clo.l_outcome_id,
                    "clo_text": clo.l_outcome,
                    "plo_id": plo.pl_outcome_id,
                    "plo_text": plo.pl_outcome,
                    "mapping_scales": scales,
                }
                build_mapping_prompt(**inputs)
                pairs.append((scenario.name, inputs))
    return pairs


class MeasuredClient:
    """Keep the response metrics while passing the original response to BedrockClient."""

    def __init__(self, client):
        self.client = client
        self.response = {}

    def converse(self, **kwargs):
        try:
            self.response = self.client.converse(**kwargs)
        except ClientError as error:
            self.response = error.response
            raise
        return self.response


def measure_pair(pair: tuple[str, dict], client) -> dict:
    """Run one mapping once, keeping metrics even if its answer fails validation."""
    scenario, inputs = pair
    # Each attempt has its own recorder, so concurrent calls cannot mix their metrics.
    measured = MeasuredClient(client)
    mapper = BedrockClient(client=measured)
    result = {}
    error_message = ""
    started_at = datetime.now(UTC).isoformat()
    started = perf_counter()
    try:
        result = map_pair(**inputs, bedrock_client=mapper)
        outcome = "mapped" if result["is_mapped"] else "unmapped"
    except (BotoCoreError, ClientError, BedrockResponseError, ValueError) as error:
        outcome = "error"
        error_message = f"{type(error).__name__}: {error}"
    elapsed = perf_counter() - started

    response = measured.response
    return {
        "scenario": scenario,
        "pair_id": f"clo-{inputs['clo_id']}__plo-{inputs['plo_id']}",
        "started_at_utc": started_at,
        "elapsed_seconds": elapsed,
        "bedrock_latency_ms": response.get("metrics", {}).get("latencyMs"),
        "input_tokens": response.get("usage", {}).get("inputTokens"),
        "output_tokens": response.get("usage", {}).get("outputTokens"),
        "request_id": response.get("ResponseMetadata", {}).get("RequestId"),
        "outcome": outcome,
        "map_labels": json.dumps(result.get("map_labels"), ensure_ascii=False),
        "explanation": result.get("explanation", ""),
        "error": error_message,
    }


def run_round(pairs: list[tuple[str, dict]], client, concurrency: int):
    """Yield completed measurements, with at most concurrency calls in flight."""
    remaining = iter(pairs)
    with ThreadPoolExecutor(max_workers=concurrency) as executor:
        pending = {
            executor.submit(measure_pair, pair, client)
            for pair in islice(remaining, concurrency)
        }
        try:
            while pending:
                completed, _ = wait(pending, return_when=FIRST_COMPLETED)
                for future in completed:
                    pending.remove(future)
                    yield future.result()
                # Only schedule replacements after recording the completed calls.
                pending.update(
                    executor.submit(measure_pair, pair, client)
                    for pair in islice(remaining, len(completed))
                )
        finally:
            # Closing the generator stops new work. Calls already sent may still finish.
            for future in pending:
                future.cancel()


def print_summary(rows: list[dict], elapsed: float) -> None:
    """Summarize recorded attempts without treating missing token usage as zero."""
    errors = sum(row["outcome"] == "error" for row in rows)
    durations = [row["elapsed_seconds"] for row in rows]
    print(f"\nRecorded attempts: {len(rows)} | Valid responses: {len(rows) - errors} | Errors: {errors}")
    print(
        f"Total elapsed: {elapsed:.2f}s | Median pair: {median(durations):.2f}s | "
        f"Slowest pair: {max(durations):.2f}s"
    )
    for field in ("input_tokens", "output_tokens"):
        available = [row[field] for row in rows if row[field] is not None]
        total = sum(available) if available else "unavailable"
        print(f"{field}: {total} reported across {len(available)}/{len(rows)} attempts")


def positive_int(value: str) -> int:
    number = int(value)
    if number < 1:
        raise argparse.ArgumentTypeError("Must be at least 1.")
    return number


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="Preview a CLO-PLO benchmark, or explicitly run billable Bedrock calls.",
        epilog="Run from the mapping API directory to use its local .env. No DynamoDB table is needed.",
    )
    parser.add_argument(
        "--inputs", type=Path, default=Path(__file__).parent / "benchmarks/mapping_inputs.json",
    )
    parser.add_argument("--repetitions", type=positive_int, default=1)
    parser.add_argument("--concurrency", type=positive_int, default=1)
    parser.add_argument("--profile", help="AWS profile for live calls, e.g. ubc-mapping.")
    parser.add_argument(
        "--output", type=Path,
        help="New local CSV file; required for --run. Existing files are not overwritten.",
    )
    parser.add_argument(
        "--run", action="store_true", help="Send the planned calls to Bedrock. Otherwise preview only.",
    )
    args = parser.parse_args(argv)
    if args.run and args.output is None:
        parser.error("--run requires --output so the measurements are saved.")

    try:
        pairs = load_pairs(args.inputs)
        planned = len(pairs) * args.repetitions
        print(f"Scenarios: {len({name for name, _ in pairs})}")
        print(f"Pairs per round: {len(pairs)} | Repetitions: {args.repetitions}")
        print(f"Planned model calls: {planned} | Concurrent calls: {args.concurrency}")
        if not args.run:
            print("Mode: preview -- no AWS calls. Add --run and --output to execute this plan.")
            return 0

        settings = get_bedrock_settings()
        print(f"Mode: live | Model: {settings.model_id} | Region: {settings.region}", flush=True)
        rows = []
        # Open the output first, so an invalid path cannot waste model calls.
        with args.output.open("x", encoding="utf-8", newline="") as output:
            session = boto3.Session(profile_name=args.profile)
            client = session.client(
                "bedrock-runtime",
                region_name=settings.region,
                config=Config(
                    retries={"mode": "standard", "total_max_attempts": 1},
                    max_pool_connections=max(10, args.concurrency),
                ),
            )
            writer = None
            started = perf_counter()
            try:
                for repetition in range(1, args.repetitions + 1):
                    round_started = perf_counter()
                    with closing(run_round(pairs, client, args.concurrency)) as measurements:
                        for row in measurements:
                            row.update(
                                repetition=repetition,
                                model_id=settings.model_id,
                                region=settings.region,
                                concurrency=args.concurrency,
                            )
                            if writer is None:
                                writer = csv.DictWriter(output, fieldnames=list(row))
                                writer.writeheader()
                            writer.writerow(row)
                            output.flush()
                            rows.append(row)
                            print(
                                f"[{len(rows)}/{planned}] {row['scenario']} / {row['pair_id']}: "
                                f"{row['outcome']} in {row['elapsed_seconds']:.2f}s",
                                flush=True,
                            )
                    print(f"Round {repetition}: {perf_counter() - round_started:.2f}s", flush=True)
            finally:
                client.close()
        print_summary(rows, perf_counter() - started)
        print(f"Results saved to {args.output}")
        return 1 if any(row["outcome"] == "error" for row in rows) else 0
    except KeyboardInterrupt:
        print(
            "\nStopped. Previously recorded results remain in the CSV; calls already sent may have completed.",
            file=sys.stderr,
        )
        return 130
    except (OSError, ValueError, BotoCoreError, ClientError) as error:
        print(f"Benchmark could not finish: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
