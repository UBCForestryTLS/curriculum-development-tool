import json
from unittest.mock import Mock

import pytest
from botocore.exceptions import ClientError, EndpointConnectionError, NoCredentialsError

from app import manage_api_keys
from app.api_key_store import ApiKeyStore
from app.api_keys import ApiKeyCredentials, generate_api_key, verify_api_key_secret


@pytest.fixture
def configured_table(table, monkeypatch, tmp_path):
    # Let the command use its normal configuration path against the simulated table.
    monkeypatch.chdir(tmp_path)
    monkeypatch.setenv("AWS_REGION", "ca-central-1")
    monkeypatch.setenv("LO_MAPPING_DYNAMODB_TABLE", table.name)
    return table


def test_create_displays_credentials_that_match_the_stored_hash(configured_table, capsys):
    assert manage_api_keys.main(["create"]) == 0
    output = capsys.readouterr()
    headers = dict(line.split(": ", 1) for line in output.out.splitlines())

    assert set(headers) == {"X-API-Key-ID", "X-API-Key-Secret"}
    record = ApiKeyStore(configured_table).get(headers["X-API-Key-ID"])
    assert verify_api_key_secret(headers["X-API-Key-Secret"], record["secret_hash"])
    assert headers["X-API-Key-Secret"] not in json.dumps(record)
    assert "cannot be retrieved later" in output.err


def test_revoke_deletes_only_the_selected_key(configured_table, capsys):
    store = ApiKeyStore(configured_table)
    selected, other = generate_api_key(), generate_api_key()
    store.create(selected)
    other_record = store.create(other)

    assert manage_api_keys.main(["revoke", selected.key_id]) == 0

    assert store.get(selected.key_id) is None
    assert store.get(other.key_id) == other_record
    assert capsys.readouterr().out == f"Revoked API key {selected.key_id}.\n"


def test_revoking_an_unknown_key_reports_that_nothing_changed(configured_table, capsys):
    assert manage_api_keys.main(["revoke", "unknown-key"]) == 0

    assert "was not found; no changes made" in capsys.readouterr().out


def test_duplicate_creation_does_not_replace_or_display_credentials(
    configured_table, monkeypatch, capsys
):
    store = ApiKeyStore(configured_table)
    existing = generate_api_key()
    original = store.create(existing)
    proposed = ApiKeyCredentials(existing.key_id, generate_api_key().secret)
    monkeypatch.setattr(manage_api_keys, "generate_api_key", lambda: proposed)

    assert manage_api_keys.main(["create"]) == 1

    output = capsys.readouterr()
    assert output.out == ""
    assert proposed.secret not in output.err
    assert "already exists" in output.err
    assert store.get(existing.key_id) == original


@pytest.mark.parametrize("operation", ["create", "revoke"])
@pytest.mark.parametrize(
    "aws_error",
    [
        ClientError(
            {"Error": {"Code": "AccessDeniedException", "Message": "sensitive-request-details"}},
            "test_operation",
        ),
        EndpointConnectionError(endpoint_url="https://sensitive-request-details.invalid"),
        NoCredentialsError(),
    ],
    ids=["access-denied", "connection-failed", "credentials-missing"],
)
def test_aws_failures_do_not_print_credentials_or_raw_error_details(
    configured_table, monkeypatch, capsys, operation, aws_error
):
    credentials = generate_api_key()
    monkeypatch.setattr(manage_api_keys, "generate_api_key", lambda: credentials)
    monkeypatch.setattr(ApiKeyStore, operation, Mock(side_effect=aws_error))
    arguments = ["create"] if operation == "create" else ["revoke", credentials.key_id]

    assert manage_api_keys.main(arguments) == 1

    output = capsys.readouterr()
    assert output.out == ""
    assert output.err
    assert credentials.secret not in output.err
    assert "sensitive-request-details" not in output.err


def test_missing_configuration_reports_what_to_set(monkeypatch, tmp_path, capsys):
    monkeypatch.chdir(tmp_path)
    monkeypatch.delenv("AWS_REGION", raising=False)
    monkeypatch.delenv("LO_MAPPING_DYNAMODB_TABLE", raising=False)

    assert manage_api_keys.main(["create"]) == 1

    output = capsys.readouterr()
    assert output.out == ""
    assert "AWS_REGION" in output.err
    assert "LO_MAPPING_DYNAMODB_TABLE" in output.err


@pytest.mark.parametrize(
    ("arguments", "exit_code"),
    [
        (["--help"], 0),
        (["create", "--help"], 0),
        (["revoke", "--help"], 0),
        ([], 2),
        (["revoke"], 2),
        (["revoke", "   "], 2),
    ],
)
def test_help_and_invalid_arguments_do_not_access_aws(monkeypatch, arguments, exit_code):
    store_constructor = Mock(side_effect=AssertionError("Should not access AWS"))
    monkeypatch.setattr(manage_api_keys, "ApiKeyStore", store_constructor)

    with pytest.raises(SystemExit) as error:
        manage_api_keys.main(arguments)

    assert error.value.code == exit_code
    store_constructor.assert_not_called()
