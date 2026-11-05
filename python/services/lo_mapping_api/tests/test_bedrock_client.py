from unittest.mock import Mock

import boto3
import pytest
from botocore.exceptions import ClientError, EndpointConnectionError, ReadTimeoutError
from botocore.httpsession import URLLib3Session
from botocore.stub import Stubber

from app.bedrock_client import BedrockClient, BedrockResponseError


@pytest.fixture
def bedrock(monkeypatch, tmp_path):
    # Use an isolated AWS setup so these tests never use our real login or local .env.
    monkeypatch.chdir(tmp_path)
    settings = {
        "AWS_ACCESS_KEY_ID": "testing",
        "AWS_SECRET_ACCESS_KEY": "testing",
        "AWS_SESSION_TOKEN": "testing",
        "AWS_CONFIG_FILE": str(tmp_path / "aws-config"),
        "AWS_SHARED_CREDENTIALS_FILE": str(tmp_path / "aws-credentials"),
        "AWS_EC2_METADATA_DISABLED": "true",
        "AWS_REGION": "ca-central-1",
        "LO_MAPPING_BEDROCK_REGION": "us-east-1",
        "LO_MAPPING_BEDROCK_MODEL_ID": "configured-test-model",
    }
    for name, value in settings.items():
        monkeypatch.setenv(name, value)
    for name in (
        "AWS_PROFILE",
        "AWS_DEFAULT_PROFILE",
        "AWS_BEARER_TOKEN_BEDROCK",
        "AWS_ENDPOINT_URL",
        "AWS_ENDPOINT_URL_BEDROCK_RUNTIME",
    ):
        monkeypatch.delenv(name, raising=False)
    monkeypatch.setattr(boto3, "DEFAULT_SESSION", None)
    # Fail immediately if a test accidentally tries to send a real request.
    monkeypatch.setattr(
        URLLib3Session,
        "send",
        Mock(side_effect=AssertionError("Bedrock tests must not access AWS")),
    )
    return BedrockClient()


@pytest.fixture
def stubber(bedrock):
    # Stubber checks the SDK request and supplies a response without contacting Bedrock.
    with Stubber(bedrock.client) as stubber:
        yield stubber
        stubber.assert_no_pending_responses()


def bedrock_response(content, stop_reason="end_turn"):
    return {
        "output": {"message": {"role": "assistant", "content": content}},
        "stopReason": stop_reason,
        "usage": {"inputTokens": 10, "outputTokens": 5, "totalTokens": 15},
        "metrics": {"latencyMs": 50},
    }


def test_client_uses_the_bedrock_region_without_automatic_retries(bedrock):
    assert bedrock.client.meta.region_name == "us-east-1"
    assert bedrock.client.meta.config.retries["total_max_attempts"] == 1


def test_generate_text_sends_the_expected_request_and_returns_only_the_answer(bedrock, stubber):
    expected_request = {
        "modelId": "configured-test-model",
        "messages": [
            {
                "role": "user",
                "content": [{"text": "Compare these learning outcomes.\n\n/no_think"}],
            }
        ],
        "inferenceConfig": {"maxTokens": 1500},
    }
    response = bedrock_response([
        {"reasoningContent": {"reasoningText": {"text": "Separate reasoning content."}}},
        {"text": "  Example "},
        {"text": "answer.\n"},
    ])
    stubber.add_response("converse", response, expected_request)

    assert bedrock.generate_text("Compare these learning outcomes.") == "Example answer."


@pytest.mark.parametrize("prompt", ["", " \n\t "])
def test_generate_text_rejects_blank_prompts_before_sending_them(bedrock, monkeypatch, prompt):
    converse = Mock()
    monkeypatch.setattr(bedrock.client, "converse", converse)

    with pytest.raises(ValueError, match="prompt must not be blank"):
        bedrock.generate_text(prompt)

    converse.assert_not_called()


@pytest.mark.parametrize("stop_reason", [
    "max_tokens",
    "guardrail_intervened",
    "content_filtered",
    "model_context_window_exceeded",
    "malformed_model_output",
    "malformed_tool_use",
    "tool_use",
    "stop_sequence",
])
def test_generate_text_rejects_unfinished_or_blocked_responses(bedrock, stubber, stop_reason):
    # Even if text is present, it should not be accepted when the model did not finish normally.
    response = bedrock_response([{"text": "An incomplete or blocked answer."}], stop_reason)
    stubber.add_response("converse", response)

    with pytest.raises(BedrockResponseError, match=stop_reason):
        bedrock.generate_text("Example prompt")


def test_generate_text_rejects_a_response_without_a_stop_reason(bedrock, monkeypatch):
    response = bedrock_response([{"text": "An answer with no completion status."}])
    del response["stopReason"]
    monkeypatch.setattr(bedrock.client, "converse", Mock(return_value=response))

    with pytest.raises(BedrockResponseError, match="did not finish"):
        bedrock.generate_text("Example prompt")


@pytest.mark.parametrize("content", [
    [],
    [{"text": " \n "}],
    [{"reasoningContent": {"reasoningText": {"text": "Reasoning without an answer."}}}],
], ids=["no-content", "blank-text", "reasoning-only"])
def test_generate_text_rejects_empty_answers(bedrock, monkeypatch, content):
    monkeypatch.setattr(bedrock.client, "converse", Mock(return_value=bedrock_response(content)))

    with pytest.raises(BedrockResponseError, match="empty text response"):
        bedrock.generate_text("Example prompt")


@pytest.mark.parametrize("response", [
    {"stopReason": "end_turn"},
    {"stopReason": "end_turn", "output": None},
    bedrock_response(None),
    bedrock_response([{"text": 123}]),
    bedrock_response([None]),
], ids=["missing-output", "null-output", "invalid-content", "non-text-answer", "invalid-block"])
def test_generate_text_rejects_malformed_message_content(bedrock, monkeypatch, response):
    # These deliberately invalid responses bypass Stubber's own response validation.
    monkeypatch.setattr(bedrock.client, "converse", Mock(return_value=response))

    with pytest.raises(BedrockResponseError, match="invalid message content"):
        bedrock.generate_text("Example prompt")


@pytest.mark.parametrize(("error_code", "http_status"), [
    ("AccessDeniedException", 403),
    ("ThrottlingException", 429),
    ("ModelTimeoutException", 408),
    ("ServiceUnavailableException", 503),
])
def test_generate_text_preserves_aws_errors_for_the_caller(bedrock, stubber, error_code, http_status):
    stubber.add_client_error("converse", service_error_code=error_code, http_status_code=http_status)

    with pytest.raises(ClientError) as error:
        bedrock.generate_text("Example prompt")

    assert error.value.response["Error"]["Code"] == error_code


@pytest.mark.parametrize("error_type", [EndpointConnectionError, ReadTimeoutError])
def test_generate_text_preserves_connection_failures(bedrock, monkeypatch, error_type):
    connection_error = error_type(endpoint_url="https://bedrock.invalid")
    monkeypatch.setattr(bedrock.client, "converse", Mock(side_effect=connection_error))

    with pytest.raises(error_type) as error:
        bedrock.generate_text("Example prompt")

    assert error.value is connection_error
