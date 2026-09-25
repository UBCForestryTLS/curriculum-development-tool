from pathlib import Path
from typing import List

from pydantic_settings import BaseSettings, SettingsConfigDict
from pydantic import field_validator, ValidationError

MODELS_DIR = Path(__file__).parents[1] / "models"

if not MODELS_DIR.is_dir():
    MODELS_DIR.mkdir()

class Settings(BaseSettings):
    ALLOWED_ORIGINS: str = ""

    AWS_REGION: str = "ca-central-1"
    AWS_S3_BUCKET: str = "text-extraction-temp"
    AWS_ACCESS_KEY_ID: str | None = None
    AWS_SECRET_ACCESS_KEY: str | None = None
    AWS_SESSION_TOKEN: str | None = None

    BERTOPIC_MODEL: str = "sentence-transformers/all-mpnet-base-v2"

    @field_validator("ALLOWED_ORIGINS")
    @classmethod
    def parse_allowed_origins(cls, v: str) -> List[str]:
        return v.split(",") if v else []

    @field_validator("BERTOPIC_MODEL")
    @classmethod
    def parse_bertopic_model(cls, v: str) -> str:
        """ 
        Ensure the BERTopic model name can be safely resolved to a path
        within the parent model directory.
        """
        v = v.strip("/")

        model_path = MODELS_DIR.joinpath(v).resolve()

        if MODELS_DIR not in model_path.parents:
            raise ValidationError("Invalid model name. Resolved installation directory exists outside of models directory.")

        return v

    model_config = SettingsConfigDict(
        env_file='.env',
        env_file_encoding='utf-8',
        case_sensitive=True,
        extra="ignore",
    )


settings = Settings()
