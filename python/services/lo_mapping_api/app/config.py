from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class _EnvironmentSettings(BaseSettings):
    """Shared loading rules for DynamoDB and Bedrock settings."""

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


def get_settings() -> Settings:
    # Load these when needed, so /health can still run without AWS configuration.
    return Settings()


def get_bedrock_settings() -> BedrockSettings:
    # Load these only for model calls, so API key management can run on its own.
    return BedrockSettings()
