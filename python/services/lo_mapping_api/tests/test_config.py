import pytest
from pydantic import ValidationError

from app.config import get_settings


@pytest.fixture(autouse=True)
def isolate_configuration(monkeypatch, tmp_path):
    # Keep these tests away from our real shell settings and local .env file.
    monkeypatch.delenv("AWS_REGION", raising=False)
    monkeypatch.delenv("LO_MAPPING_DYNAMODB_TABLE", raising=False)
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
