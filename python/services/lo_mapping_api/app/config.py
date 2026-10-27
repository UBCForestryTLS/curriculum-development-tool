from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Connection settings for the mapping API's DynamoDB table."""

    model_config = SettingsConfigDict(
        # Read a local .env from the working directory; environment variables take priority.
        env_file=".env",
        env_file_encoding="utf-8",
        case_sensitive=True,
        str_strip_whitespace=True,
        extra="ignore",
    )

    # Require both values so we don't silently use the wrong table or region.
    aws_region: str = Field(validation_alias="AWS_REGION", min_length=1)
    dynamodb_table_name: str = Field(validation_alias="LO_MAPPING_DYNAMODB_TABLE", min_length=1)


def get_settings() -> Settings:
    # Load these when needed, so /health can still run without AWS configuration.
    return Settings()
