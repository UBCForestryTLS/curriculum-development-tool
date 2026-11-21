import hashlib
import secrets
from dataclasses import dataclass, field


@dataclass(frozen=True)
class ApiKeyCredentials:
    key_id: str
    # Keep the secret out of the object's normal printed representation.
    secret: str = field(repr=False)


def generate_api_key() -> ApiKeyCredentials:
    """Generate the ID and secret that Laravel will send in separate headers."""
    return ApiKeyCredentials(
        key_id=secrets.token_hex(16),
        secret=secrets.token_urlsafe(32),
    )


def hash_api_key_secret(secret: str) -> str:
    """Return a SHA-256 hash for storing a generated API secret."""
    if not secret.strip():
        raise ValueError("API key secret must not be blank.")

    # These secrets have 256 random bits. User passwords need a dedicated password hasher.
    return hashlib.sha256(secret.encode("utf-8")).hexdigest()


def verify_api_key_secret(secret: str, secret_hash: str) -> bool:
    """Check the submitted secret against its stored hash."""
    if not secret.strip() or len(secret_hash) != 64 or not secret_hash.isascii():
        return False

    # Compare the exact secret; trimming or changing its case would accept a different value.
    submitted_hash = hash_api_key_secret(secret)
    return secrets.compare_digest(submitted_hash, secret_hash)
