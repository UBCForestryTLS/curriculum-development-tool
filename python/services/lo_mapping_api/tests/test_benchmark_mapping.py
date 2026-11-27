import csv
import json
from collections import Counter
from itertools import count
from threading import Barrier, Lock
from unittest.mock import Mock

import pytest
from botocore.exceptions import ClientError
from botocore.httpsession import URLLib3Session

import benchmark_mapping as benchmark


@pytest.fixture(autouse=True)
def aws_session(monkeypatch, tmp_path):
    # Keep our local settings and AWS login out of these tests.
    monkeypatch.chdir(tmp_path)
    monkeypatch.setenv("LO_MAPPING_BEDROCK_REGION", "us-west-2")
    monkeypatch.setenv("LO_MAPPING_BEDROCK_MODEL_ID", "test-model")
    session = Mock(side_effect=AssertionError("Tests must not create a real AWS session."))
    monkeypatch.setattr(benchmark.boto3, "Session", session)
    for target, method in ((benchmark.boto3, "client"), (URLLib3Session, "send")):
        monkeypatch.setattr(target, method, Mock(side_effect=AssertionError("No AWS calls in tests.")))
    return session


@pytest.fixture
def inputs_path(job_payload, tmp_path):
    scenario = {
        "name": "forest-outcomes",
        "course_learning_outcomes": job_payload["course_learning_outcomes"],
        "program_learning_outcomes": job_payload["program_learning_outcomes"],
        "mapping_scales": job_payload["mapping_scales"],
    }
    path = tmp_path / "inputs.json"
    path.write_text(json.dumps([scenario]), encoding="utf-8")
    return path


def bedrock_response(pair_id, index):
    # Different answers and metrics make it clear if two pairs get mixed up.
    answer = {
        "id": pair_id,
        "is_mapped": index % 2 == 0,
        "mapLabels": ["FOUND"] if index % 2 == 0 else [],
        "explanation": f"Example decision for {pair_id}.",
    }
    return {
        "output": {"message": {"role": "assistant", "content": [{"text": json.dumps(answer)}]}},
        "stopReason": "end_turn",
        "usage": {"inputTokens": 100 + index, "outputTokens": 20 + index},
        "metrics": {"latencyMs": 50 + index},
        "ResponseMetadata": {"RequestId": f"request-{pair_id}"},
    }


def test_load_pairs_creates_all_clo_plo_combinations(inputs_path):
    pairs = benchmark.load_pairs(inputs_path)

    assert [(pair["clo_id"], pair["plo_id"]) for _, pair in pairs] == [
        (21, 34), (21, 35), (21, 36), (22, 34), (22, 35), (22, 36),
    ]


def test_preview_reports_planned_calls_without_settings_or_aws(monkeypatch, aws_session, capsys):
    monkeypatch.delenv("LO_MAPPING_BEDROCK_REGION")
    monkeypatch.delenv("LO_MAPPING_BEDROCK_MODEL_ID")

    assert benchmark.main(["--repetitions", "2", "--concurrency", "2"]) == 0

    output = capsys.readouterr().out
    assert "Planned model calls: 12 | Concurrent calls: 2" in output
    aws_session.assert_not_called()


@pytest.mark.parametrize("failure", [None, "invalid-json", "aws-error"])
def test_run_records_each_attempt_and_continues_after_failed_pairs(
    inputs_path, tmp_path, aws_session, monkeypatch, failure
):
    pair_ids = [
        "clo-21__plo-34", "clo-21__plo-35", "clo-21__plo-36",
        "clo-22__plo-34", "clo-22__plo-35", "clo-22__plo-36",
    ]
    responses = [bedrock_response(pair_id, index) for index, pair_id in enumerate(pair_ids)]
    if failure == "invalid-json":
        responses[0]["output"]["message"]["content"] = [{"text": "Not JSON."}]
    elif failure == "aws-error":
        responses[0] = ClientError({"Error": {"Code": "ThrottlingException"}}, "Converse")
    client = Mock(spec=["converse", "close"])
    client.converse.side_effect = responses * 2
    aws_session.side_effect = None
    aws_session.return_value.client.return_value = client
    # Use a fixed clock so we check elapsed seconds without depending on machine speed.
    monkeypatch.setattr(benchmark, "perf_counter", Mock(side_effect=count()))
    output_path = tmp_path / "results.csv"

    exit_code = benchmark.main([
        "--inputs", str(inputs_path), "--repetitions", "2", "--run", "--output", str(output_path),
    ])

    assert exit_code == (1 if failure else 0)
    assert client.converse.call_count == 12
    client.close.assert_called_once()
    config = aws_session.return_value.client.call_args.kwargs["config"]
    assert config.retries["total_max_attempts"] == 1
    with output_path.open(newline="", encoding="utf-8") as output:
        rows = list(csv.DictReader(output))
    assert [(row["repetition"], row["pair_id"]) for row in rows] == [
        (str(repetition), pair_id) for repetition in (1, 2) for pair_id in pair_ids
    ]
    assert Counter(row["outcome"] for row in rows) == (
        {"mapped": 4, "unmapped": 6, "error": 2} if failure else {"mapped": 6, "unmapped": 6}
    )
    assert all(float(row["elapsed_seconds"]) == 1 for row in rows)
    assert rows[0]["input_tokens"] == ("" if failure == "aws-error" else "100")
    assert bool(rows[0]["error"]) == bool(failure)


def test_concurrent_calls_keep_each_pairs_results_and_metrics(inputs_path):
    pairs = benchmark.load_pairs(inputs_path)
    responses = {
        f"{benchmark.build_mapping_prompt(**inputs)}\n\n/no_think": bedrock_response(
            f"clo-{inputs['clo_id']}__plo-{inputs['plo_id']}", index,
        )
        for index, (_, inputs) in enumerate(pairs)
    }
    barrier, lock = Barrier(2, timeout=5), Lock()
    active = peak = 0

    def converse(**kwargs):
        nonlocal active, peak
        with lock:
            active += 1
            peak = max(peak, active)
        try:
            # Make calls overlap without sleeps or assumptions about which finishes first.
            barrier.wait()
            return responses[kwargs["messages"][0]["content"][0]["text"]]
        finally:
            with lock:
                active -= 1

    client = Mock(spec=["converse"], converse=Mock(side_effect=converse))
    rows = list(benchmark.run_round(pairs, client, concurrency=2))

    assert peak == 2
    assert client.converse.call_count == len(rows) == 6
    by_pair = {row["pair_id"]: row for row in rows}
    for index, (_, inputs) in enumerate(pairs):
        pair_id = f"clo-{inputs['clo_id']}__plo-{inputs['plo_id']}"
        row = by_pair[pair_id]
        assert row["outcome"] == ("mapped" if index % 2 == 0 else "unmapped")
        assert (row["input_tokens"], row["output_tokens"], row["bedrock_latency_ms"]) == (
            100 + index, 20 + index, 50 + index,
        )
        assert row["request_id"] == f"request-{pair_id}"


def test_run_requires_a_new_output_file(tmp_path, aws_session):
    with pytest.raises(SystemExit) as error:
        benchmark.main(["--run"])
    assert error.value.code == 2

    output_path = tmp_path / "existing.csv"
    output_path.write_text("Previous measurements.\n", encoding="utf-8")
    assert benchmark.main(["--run", "--output", str(output_path)]) == 1
    assert output_path.read_text(encoding="utf-8") == "Previous measurements.\n"
    aws_session.assert_not_called()
