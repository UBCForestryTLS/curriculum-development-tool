from typing import Annotated

from botocore.exceptions import BotoCoreError, ClientError
from fastapi import Header, HTTPException, status
from pydantic import ValidationError

from app.api_key_store import ApiKeyStore
from app.api_keys import verify_api_key_secret


def require_api_key(
    key_id: Annotated[str | None, Header(alias="X-API-Key-ID")] = None,
    key_secret: Annotated[str | None, Header(alias="X-API-Key-Secret")] = None,
) -> str:
    """Use Depends(require_api_key) on protected routes to get the verified key ID."""
    unauthorized = HTTPException(
        status_code=status.HTTP_401_UNAUTHORIZED,
        detail="Invalid or missing API credentials.",
        headers={"WWW-Authenticate": "APIKey"},
    )
    # Handle missing headers here so they get the same 401 as an incorrect key.
    if not key_id or not key_id.strip() or not key_secret or not key_secret.strip():
        raise unauthorized

    try:
        record = ApiKeyStore().get(key_id)
    except (ValidationError, BotoCoreError, ClientError):
        # An AWS or configuration failure does not mean the client's key is wrong.
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="Authentication service is unavailable.",
        ) from None

    stored_hash = record.get("secret_hash") if record is not None else None
    if not isinstance(stored_hash, str) or not verify_api_key_secret(key_secret, stored_hash):
        raise unauthorized

    # Routes only need the identity, so keep the secret and stored hash here.
    return key_id
