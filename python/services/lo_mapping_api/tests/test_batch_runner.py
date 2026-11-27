import json
from threading import Barrier, Lock, get_ident
from types import SimpleNamespace
from unittest.mock import Mock, patch

import pytest
from botocore.exceptions import EndpointConnectionError, ReadTimeoutError
from botocore.httpsession import URLLib3Session

import app.batch_runner as runner
from app.bedrock_client import BedrockClient, BedrockResponseError
from app.config import BatchSettings
from app.job_store import MappingJobStore


@pytest.fixture(autouse=True)
def clock(monkeypatch):
    # Move time forward ourselves so timing tests don't need sleeps or depend on machine speed.
    clock = SimpleNamespace(now=0.0)
    monkeypatch.setattr(runner, "monotonic", lambda: clock.now)
    # The table uses Moto and the model is simulated. Any real network request should fail.
    monkeypatch.setattr(URLLib3Session, "send", Mock(side_effect=AssertionError("No real AWS calls in tests")))
    return clock


@pytest.fixture
def store(table, job_request):
    store = MappingJobStore(table)
    store.create(job_request)
    return store


@pytest.fixture
def settings():
    return BatchSettings(_env_file=None, LO_MAPPING_BATCH_SIZE=4, LO_MAPPING_CONCURRENCY=2)


def model_reply(prompt, labels=("FOUND", "APPLY")):
    # Read the pair from the real prompt so each fake answer belongs to the right request.
    pair = json.loads(prompt[prompt.index("{"):])
    return json.dumps({
        "id": pair["id"],
        "is_mapped": bool(labels),
        "mapLabels": list(labels),
        "explanation": f"Alignment for {pair['id']}.",
    })


@pytest.fixture
def bedrock():
    client = Mock(spec=BedrockClient)
    client.generate_text.side_effect = model_reply
    return client


@pytest.mark.parametrize("concurrency", [1, 2])
def test_batches_respect_limits_and_resume_saved_progress(store, job_request, settings, bedrock, concurrency):
    settings.concurrency = concurrency
    barrier, lock = Barrier(concurrency, timeout=5), Lock()
    active = peak = 0

    def concurrent_reply(prompt):
        nonlocal active, peak
        with lock:
            active += 1
            peak = max(peak, active)
        # Hold calls together so we can check the number actually running at the same time.
        barrier.wait()
        with lock:
            active -= 1
        return model_reply(prompt)

    bedrock.generate_text.side_effect = concurrent_reply
    # Only model calls run in workers; the shared DynamoDB resource stays on this thread.
    main_thread = get_ident()
    original_save = store.save_pair_result

    def save_on_main_thread(*args, **kwargs):
        assert get_ident() == main_thread
        return original_save(*args, **kwargs)

    with patch.object(store, "save_pair_result", side_effect=save_on_main_thread):
        first = runner.run_batch(job_request.job_id, store=store, bedrock_client=bedrock, settings=settings)
        assert first["processed_pairs"] == 4 and first["status"] == "in_progress"
        assert bedrock.generate_text.call_count == 4
        # Release after each batch so the next request can pick up the remaining work.
        assert "batch_lock_token" not in store.get(job_request.job_id)
        # The second batch should only send the two remaining pairs.
        finished = runner.run_batch(job_request.job_id, store=store, bedrock_client=bedrock, settings=settings)
    assert peak == concurrency
    assert finished["status"] == "completed"
    assert finished["processed_pairs"] == finished["mapped_pairs"] == 6
    assert "batch_lock_token" not in store.get(job_request.job_id)
    for pair in store.get_pairs(job_request.job_id):
        assert pair["mapping_scales"] == [3, 8]
        assert pair["explanation"] == f"Alignment for clo-{pair['clo_id']}__plo-{pair['plo_id']}."

    # Calling again after completion should return progress without sending more prompts.
    assert runner.run_batch(job_request.job_id, store=store, bedrock_client=bedrock, settings=settings) == finished
    assert bedrock.generate_text.call_count == 6


@pytest.mark.parametrize("bad_answer", ["not JSON", BedrockResponseError("Response was cut off.")])
def test_matches_no_matches_and_invalid_answers_have_separate_counts(
    store, job_request, settings, bedrock, bad_answer
):
    settings.batch_size = 6

    def mixed_reply(prompt):
        pair = json.loads(prompt[prompt.index("{"):])
        if pair["id"].endswith("plo-34"):
            if isinstance(bad_answer, Exception):
                raise bad_answer
            return bad_answer
        if pair["id"].endswith("plo-35"):
            return model_reply(prompt, labels=())
        return model_reply(prompt)

    # Each CLO gets one invalid answer, one no-match and one match.
    bedrock.generate_text.side_effect = mixed_reply
    finished = runner.run_batch(job_request.job_id, store=store, bedrock_client=bedrock, settings=settings)
    assert (finished["processed_pairs"], finished["mapped_pairs"], finished["errored_pairs"]) == (4, 2, 2)
    assert finished["status"] == "failed"
    assert sum(pair["error"] is not None for pair in store.get_pairs(job_request.job_id)) == 2
    assert store.get_pending_pairs(job_request.job_id, limit=6) == []


def test_aws_failure_stops_requests_and_leaves_unsaved_pairs_pending(store, job_request, settings, bedrock):
    settings.concurrency = 1
    failure = ReadTimeoutError(endpoint_url="https://bedrock.invalid")
    bedrock.generate_text.side_effect = failure

    # A connection failure isn't a mapping decision and shouldn't count as an invalid answer.
    with pytest.raises(ReadTimeoutError) as error:
        runner.run_batch(job_request.job_id, store=store, bedrock_client=bedrock, settings=settings)
    assert error.value is failure
    assert bedrock.generate_text.call_count == 1
    assert store.get(job_request.job_id)["errored_pairs"] == 0
    assert len(store.get_pending_pairs(job_request.job_id, limit=6)) == 6
    assert "batch_lock_token" not in store.get(job_request.job_id)


def test_setup_failure_releases_lock_before_any_model_calls(store, job_request, settings, bedrock):
    failure = EndpointConnectionError(endpoint_url="https://dynamodb.invalid")
    # The lock is already held when fetching pairs fails. It must still be released.
    with patch.object(store, "get_pending_pairs", side_effect=failure):
        with pytest.raises(EndpointConnectionError):
            runner.run_batch(job_request.job_id, store=store, bedrock_client=bedrock, settings=settings)

    bedrock.generate_text.assert_not_called()
    assert "batch_lock_token" not in store.get(job_request.job_id)


def test_storage_failure_collects_active_calls_and_can_resume(store, job_request, settings, bedrock):
    barrier = Barrier(2, timeout=5)

    def concurrent_reply(prompt):
        barrier.wait()
        return model_reply(prompt)

    bedrock.generate_text.side_effect = concurrent_reply
    original_save = store.save_pair_result
    attempts = []
    failure = EndpointConnectionError(endpoint_url="https://dynamodb.invalid")

    def fail_first_save(*args, **kwargs):
        attempts.append(args)
        if len(attempts) == 1:
            raise failure
        return original_save(*args, **kwargs)

    # Both calls were sent before the first save failed. Still save the other answer, then stop.
    with patch.object(store, "save_pair_result", side_effect=fail_first_save):
        with pytest.raises(EndpointConnectionError) as error:
            runner.run_batch(job_request.job_id, store=store, bedrock_client=bedrock, settings=settings)
    assert error.value is failure
    assert bedrock.generate_text.call_count == len(attempts) == 2
    assert store.get(job_request.job_id)["processed_pairs"] == 1
    assert "batch_lock_token" not in store.get(job_request.job_id)

    # After storage recovers, only the five unsaved pairs should need another attempt.
    bedrock.generate_text.side_effect = model_reply
    settings.batch_size = 6
    finished = runner.run_batch(job_request.job_id, store=store, bedrock_client=bedrock, settings=settings)
    assert finished["status"] == "completed" and finished["processed_pairs"] == 6
    assert bedrock.generate_text.call_count == 7


@pytest.mark.parametrize("delay_stage", ["setup", "model"])
def test_time_cutoff_leaves_unstarted_work_for_later(store, job_request, settings, bedrock, clock, delay_stage):
    settings.concurrency = 1
    target, method, original = bedrock, "generate_text", bedrock.generate_text
    if delay_stage == "setup":
        target, method, original = store, "get_pending_pairs", store.get_pending_pairs

    def reach_cutoff(*args, **kwargs):
        # Eight seconds closes the starting window, even though the overall budget is twenty.
        clock.now = 8
        return original(*args, **kwargs)

    with patch.object(target, method, side_effect=reach_cutoff):
        progress = runner.run_batch(job_request.job_id, store=store, bedrock_client=bedrock, settings=settings)
    expected_saved = 1 if delay_stage == "model" else 0
    assert progress["processed_pairs"] == expected_saved
    assert bedrock.generate_text.call_count == expected_saved
    assert len(store.get_pending_pairs(job_request.job_id, limit=6)) == 6 - expected_saved

    # The active call's result stays saved, and the next batch can pick up everything else.
    settings.batch_size = 6
    finished = runner.run_batch(job_request.job_id, store=store, bedrock_client=bedrock, settings=settings)
    assert finished["status"] == "completed"
    assert bedrock.generate_text.call_count == 6


def test_runner_passes_timeouts_to_the_default_clients(table, store, job_request, settings, monkeypatch):
    monkeypatch.setenv("AWS_REGION", "ca-central-1")
    monkeypatch.setenv("LO_MAPPING_DYNAMODB_TABLE", table.name)
    monkeypatch.setenv("LO_MAPPING_BEDROCK_REGION", "us-west-2")
    monkeypatch.setenv("LO_MAPPING_BEDROCK_MODEL_ID", "test-model")

    # Build the real SDK clients with fake credentials, replacing only the model response.
    with (
        patch.object(runner, "MappingJobStore", wraps=MappingJobStore) as create_store,
        patch.object(runner, "BedrockClient", wraps=BedrockClient) as create_bedrock,
        patch.object(BedrockClient, "generate_text", side_effect=model_reply),
    ):
        progress = runner.run_batch(job_request.job_id, settings=settings)
    storage_config = create_store.call_args.kwargs["config"]
    model_config = create_bedrock.call_args.kwargs["config"]
    assert storage_config.connect_timeout == 2 and storage_config.read_timeout == 3
    assert storage_config.retries["total_max_attempts"] == 1
    assert model_config.connect_timeout == 2 and model_config.read_timeout == 8
    assert model_config.max_pool_connections == settings.concurrency
    assert progress["processed_pairs"] == 4
