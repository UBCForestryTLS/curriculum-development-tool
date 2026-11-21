import pytest
from pydantic import ValidationError

from app.config import get_bedrock_settings, get_settings


@pytest.fixture(autouse=True)
def isolate_configuration(monkeypatch, tmp_path):
    # Keep these tests away from our real shell settings and local .env file.
    monkeypatch.delenv("AWS_REGION", raising=False)
    monkeypatch.delenv("LO_MAPPING_DYNAMODB_TABLE", raising=False)
    monkeypatch.delenv("LO_MAPPING_BEDROCK_REGION", raising=False)
    monkeypatch.delenv("LO_MAPPING_BEDROCK_MODEL_ID", raising=False)
    monkeypatch.chdir(tmp_path)


def test_settings_load_from_environment_variables(monkeypatch):
    monkeypatch.setenv("AWS_REGION", " ca-central-1 ")
    monkeypatch.setenv("LO_MAPPING_DYNAMODB_TABLE", " lo-mapping-test ")

    settings = get_settings()

    assert settings.aws_region == "ca-central-1"
    assert settings.dynamodb_table_name == "lo-mapping-test"


def test_settings_load_from_local_env_file(tmp_path):
    (tmp_path / ".env").write_text(
        "AWS_REGION=ca-central-1\n"
        "LO_MAPPING_DYNAMODB_TABLE=lo-mapping-local\n"
        "UNRELATED_SETTING=ignored\n",
        encoding="utf-8",
    )

    settings = get_settings()

    assert settings.aws_region == "ca-central-1"
    assert settings.dynamodb_table_name == "lo-mapping-local"


def test_environment_variables_override_local_env_file(monkeypatch, tmp_path):
    (tmp_path / ".env").write_text(
        "AWS_REGION=ca-central-1\n"
        "LO_MAPPING_DYNAMODB_TABLE=lo-mapping-local\n",
        encoding="utf-8",
    )
    monkeypatch.setenv("AWS_REGION", "ca-west-1")
    monkeypatch.setenv("LO_MAPPING_DYNAMODB_TABLE", "lo-mapping-deployed")

    settings = get_settings()

    assert settings.aws_region == "ca-west-1"
    assert settings.dynamodb_table_name == "lo-mapping-deployed"


@pytest.mark.parametrize("setting_name", ["AWS_REGION", "LO_MAPPING_DYNAMODB_TABLE"])
def test_settings_identify_missing_configuration(monkeypatch, setting_name):
    monkeypatch.setenv("AWS_REGION", "ca-central-1")
    monkeypatch.setenv("LO_MAPPING_DYNAMODB_TABLE", "lo-mapping-test")
    monkeypatch.delenv(setting_name)

    with pytest.raises(ValidationError) as error:
        get_settings()

    assert error.value.errors()[0]["loc"] == (setting_name,)
    assert error.value.errors()[0]["type"] == "missing"


@pytest.mark.parametrize("setting_name", ["AWS_REGION", "LO_MAPPING_DYNAMODB_TABLE"])
@pytest.mark.parametrize("value", ["", "   "])
def test_settings_reject_empty_or_whitespace_values(monkeypatch, setting_name, value):
    monkeypatch.setenv("AWS_REGION", "ca-central-1")
    monkeypatch.setenv("LO_MAPPING_DYNAMODB_TABLE", "lo-mapping-test")
    monkeypatch.setenv(setting_name, value)

    with pytest.raises(ValidationError) as error:
        get_settings()

    assert error.value.errors()[0]["loc"] == (setting_name,)
    assert error.value.errors()[0]["type"] == "string_too_short"


def test_bedrock_settings_load_from_environment_without_dynamodb_settings(monkeypatch):
    monkeypatch.setenv("LO_MAPPING_BEDROCK_REGION", " us-west-2 ")
    monkeypatch.setenv("LO_MAPPING_BEDROCK_MODEL_ID", " qwen.qwen3-32b-v1:0 ")

    settings = get_bedrock_settings()

    assert settings.region == "us-west-2"
    assert settings.model_id == "qwen.qwen3-32b-v1:0"


def test_bedrock_and_dynamodb_settings_load_separate_regions_from_env_file(tmp_path):
    # Both services share the .env file, but each should use its own region.
    (tmp_path / ".env").write_text(
        "AWS_REGION=ca-central-1\n"
        "LO_MAPPING_DYNAMODB_TABLE=lo-mapping-local\n"
        "LO_MAPPING_BEDROCK_REGION=us-west-2\n"
        "LO_MAPPING_BEDROCK_MODEL_ID=qwen.qwen3-32b-v1:0\n"
        "UNRELATED_SETTING=ignored\n",
        encoding="utf-8",
    )

    dynamodb_settings = get_settings()
    bedrock_settings = get_bedrock_settings()

    assert dynamodb_settings.aws_region == "ca-central-1"
    assert dynamodb_settings.dynamodb_table_name == "lo-mapping-local"
    assert bedrock_settings.region == "us-west-2"
    assert bedrock_settings.model_id == "qwen.qwen3-32b-v1:0"


def test_bedrock_environment_variables_override_local_env_file(monkeypatch, tmp_path):
    (tmp_path / ".env").write_text(
        "LO_MAPPING_BEDROCK_REGION=us-west-2\n"
        "LO_MAPPING_BEDROCK_MODEL_ID=local-model\n",
        encoding="utf-8",
    )
    monkeypatch.setenv("LO_MAPPING_BEDROCK_REGION", "us-east-1")
    monkeypatch.setenv("LO_MAPPING_BEDROCK_MODEL_ID", "qwen.qwen3-32b-v1:0")

    settings = get_bedrock_settings()

    assert settings.region == "us-east-1"
    assert settings.model_id == "qwen.qwen3-32b-v1:0"


@pytest.mark.parametrize("setting_name", ["LO_MAPPING_BEDROCK_REGION", "LO_MAPPING_BEDROCK_MODEL_ID"])
def test_bedrock_settings_identify_missing_configuration(monkeypatch, setting_name):
    # AWS_REGION is for DynamoDB and should not replace a missing Bedrock region.
    monkeypatch.setenv("AWS_REGION", "ca-central-1")
    monkeypatch.setenv("LO_MAPPING_BEDROCK_REGION", "us-west-2")
    monkeypatch.setenv("LO_MAPPING_BEDROCK_MODEL_ID", "qwen.qwen3-32b-v1:0")
    monkeypatch.delenv(setting_name)

    with pytest.raises(ValidationError) as error:
        get_bedrock_settings()

    assert error.value.errors()[0]["loc"] == (setting_name,)
    assert error.value.errors()[0]["type"] == "missing"


@pytest.mark.parametrize("setting_name", ["LO_MAPPING_BEDROCK_REGION", "LO_MAPPING_BEDROCK_MODEL_ID"])
@pytest.mark.parametrize("value", ["", "   "])
def test_bedrock_settings_reject_empty_or_whitespace_values(monkeypatch, setting_name, value):
    monkeypatch.setenv("LO_MAPPING_BEDROCK_REGION", "us-west-2")
    monkeypatch.setenv("LO_MAPPING_BEDROCK_MODEL_ID", "qwen.qwen3-32b-v1:0")
    monkeypatch.setenv(setting_name, value)

    with pytest.raises(ValidationError) as error:
        get_bedrock_settings()

    assert error.value.errors()[0]["loc"] == (setting_name,)
    assert error.value.errors()[0]["type"] == "string_too_short"


def test_invalid_bedrock_settings_do_not_block_dynamodb_configuration(monkeypatch):
    monkeypatch.setenv("AWS_REGION", "ca-central-1")
    monkeypatch.setenv("LO_MAPPING_DYNAMODB_TABLE", "lo-mapping-test")
    monkeypatch.setenv("LO_MAPPING_BEDROCK_REGION", "   ")
    monkeypatch.setenv("LO_MAPPING_BEDROCK_MODEL_ID", "")

    settings = get_settings()

    assert settings.aws_region == "ca-central-1"
    assert settings.dynamodb_table_name == "lo-mapping-test"
