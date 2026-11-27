from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class _EnvironmentSettings(BaseSettings):
    """Shared loading rules for the mapping service's settings."""

    model_config = SettingsConfigDict(
        # Read a local .env from the working directory; environment variables take priority.
        env_file=".env",
        env_file_encoding="utf-8",
        case_sensitive=True,
        str_strip_whitespace=True,
        extra="ignore",
    )


class Settings(_EnvironmentSettings):
    """Connection settings for the mapping API's DynamoDB table."""

    # Require both values so we don't silently use the wrong table or region.
    aws_region: str = Field(validation_alias="AWS_REGION", min_length=1)
    dynamodb_table_name: str = Field(validation_alias="LO_MAPPING_DYNAMODB_TABLE", min_length=1)


class BedrockSettings(_EnvironmentSettings):
    """Region and model to use for Bedrock inference."""

    region: str = Field(validation_alias="LO_MAPPING_BEDROCK_REGION", min_length=1)
    model_id: str = Field(validation_alias="LO_MAPPING_BEDROCK_MODEL_ID", min_length=1)


class BatchSettings(_EnvironmentSettings):
    """Limits for how many pairs a batch can process and how many calls can run together."""

    # These are starting values; adjust them after testing the complete API flow.
    batch_size: int = Field(default=12, validation_alias="LO_MAPPING_BATCH_SIZE", gt=0)
    concurrency: int = Field(default=2, validation_alias="LO_MAPPING_CONCURRENCY", gt=0)


def get_settings() -> Settings:
    # Load these when needed, so /health can still run without AWS configuration.
    return Settings()


def get_bedrock_settings() -> BedrockSettings:
    # Load these only for model calls, so API key management can run on its own.
    return BedrockSettings()


def get_batch_settings() -> BatchSettings:
    return BatchSettings()
