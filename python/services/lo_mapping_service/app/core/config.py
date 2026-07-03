from typing import List
from pydantic_settings import BaseSettings, SettingsConfigDict
from pydantic import field_validator


class Settings(BaseSettings):
    ALLOWED_ORIGINS: str = ""
    LO_MAPPING_DYNAMODB_REQUESTS_TABLE: str | None = None
    AWS_REGION: str | None = None
    ACCESS_KEY: str | None = None
    SECRET_KEY: str | None = None
    DYNAMODB_STATUS_INDEX: str = "status-created_at-index"
    OUTPUT_S3_URI: str | None = None
    BATCH_TRANSFORM_INPUT_S3_BUCKET: str | None = None
    LARAVEL_API_URL: str | None = None
    APP_NAME: str = "curriculum-development-tool"

    @field_validator("ALLOWED_ORIGINS")
    def parse_allowed_origins(cls, v: str) -> List[str]:
        return v.split(",") if v else []

    model_config = SettingsConfigDict(
        env_file='.env',
        env_file_encoding='utf-8',
        case_sensitive=True,
        extra="ignore",
    )


settings = Settings()