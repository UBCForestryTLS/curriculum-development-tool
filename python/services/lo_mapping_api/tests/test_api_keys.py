import re

import pytest

from app.api_keys import generate_api_key, hash_api_key_secret, verify_api_key_secret


def test_generated_credentials_are_distinct_and_safe_for_headers():
    first = generate_api_key()
    second = generate_api_key()

    assert first.key_id != second.key_id
    assert first.secret != second.secret
    for credentials in (first, second):
        assert re.fullmatch(r"[0-9a-f]{32}", credentials.key_id)
        assert re.fullmatch(r"[A-Za-z0-9_-]{43}", credentials.secret)


def test_credentials_do_not_include_the_secret_in_their_representation():
    credentials = generate_api_key()

    assert credentials.key_id in repr(credentials)
    assert credentials.secret not in repr(credentials)


def test_generated_secret_can_be_verified_from_its_hash():
    credentials = generate_api_key()
    secret_hash = hash_api_key_secret(credentials.secret)

    assert re.fullmatch(r"[0-9a-f]{64}", secret_hash)
    assert verify_api_key_secret(credentials.secret, secret_hash)
    # Knowing the stored hash should not let someone use it as the API secret.
    assert not verify_api_key_secret(secret_hash, secret_hash)


@pytest.mark.parametrize(
    "submitted_secret",
    [
        "wrong-secret",
        "",
        "   ",
        " example-api-secret",
        "example-api-secret ",
        "EXAMPLE-API-SECRET",
        "é",
    ],
)
def test_verification_rejects_incorrect_or_changed_secrets(submitted_secret):
    secret_hash = hash_api_key_secret("example-api-secret")

    assert not verify_api_key_secret(submitted_secret, secret_hash)


@pytest.mark.parametrize("secret_hash", ["", "not-a-hash", "x" * 64, "é" * 64])
def test_verification_rejects_invalid_stored_hashes(secret_hash):
    assert not verify_api_key_secret("example-api-secret", secret_hash)


@pytest.mark.parametrize("secret", ["", "   ", "\t\n"])
def test_hashing_rejects_blank_secrets(secret):
    with pytest.raises(ValueError, match="must not be blank"):
        hash_api_key_secret(secret)
