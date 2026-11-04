import boto3
from botocore.config import Config

from app.config import get_bedrock_settings


class BedrockResponseError(RuntimeError):
    """Bedrock returned an incomplete or unusable text response."""


class BedrockClient:
    """Send prompts to Qwen through Bedrock. AWS errors are left for the caller to handle."""

    def __init__(self, client=None):
        settings = get_bedrock_settings()
        self.model_id = settings.model_id
        if client is None:
            # Boto3 uses the local AWS login or Lambda's role, just like the key store.
            client = boto3.client(
                "bedrock-runtime",
                region_name=settings.region,
                # Leave retries to the job runner so one call does not silently run again.
                config=Config(retries={"mode": "standard", "total_max_attempts": 1}),
            )
        self.client = client

    def generate_text(self, prompt: str) -> str:
        """Return the model's answer. Mapping JSON is validated separately by the caller."""
        if not prompt.strip():
            raise ValueError("Bedrock prompt must not be blank.")

        response = self.client.converse(
            modelId=self.model_id,

            messages=[
                {
                    "role": "user",
                    # Qwen uses /no_think in the prompt to request a non-thinking response.
                    "content": [{"text": f"{prompt}\n\n/no_think"}],
                }
            ],
            # Keep the existing per-pair output limit while we test the new model.
            inferenceConfig={"maxTokens": 1500},
            
        )

        #see https://docs.aws.amazon.com/bedrock/latest/userguide/response-structure.html for the response structure

        # A token limit or blocked response must not look like a completed mapping.
        stop_reason = response.get("stopReason")
        if stop_reason != "end_turn":
            raise BedrockResponseError(
                f"Bedrock did not finish the response (stop reason: {stop_reason})."
            )

        try:
            content = response["output"]["message"]["content"]
            if not isinstance(content, list):
                raise BedrockResponseError("Bedrock returned invalid message content.")
            # Converse separates reasoning from the answer, so only read the text blocks.
            text = "".join(block["text"] for block in content if "text" in block).strip()
        except (KeyError, TypeError):
            raise BedrockResponseError("Bedrock returned invalid message content.") from None

        if not text:
            raise BedrockResponseError("Bedrock returned an empty text response.")
        return text
