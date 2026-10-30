from datetime import UTC, datetime

import boto3

from app.api_keys import ApiKeyCredentials, hash_api_key_secret
from app.config import get_settings


class ApiKeyStore:
    """Store API key hashes in DynamoDB. AWS errors are left for the caller to handle."""

    def __init__(self, table=None):
        if table is None:
            settings = get_settings()
            # Boto3 uses the local AWS login or Lambda's role to access the table.
            table = boto3.resource("dynamodb", region_name=settings.aws_region).Table(
                settings.dynamodb_table_name
            )
        self.table = table

    @staticmethod
    def _key(key_id: str) -> dict[str, str]:
        if not key_id.strip():
            raise ValueError("API key ID must not be blank.")

        # Every item needs both keys, even though each API key has just one record.
        return {"pk": f"API_KEY#{key_id}", "sk": "METADATA"}

    def create(self, credentials: ApiKeyCredentials) -> dict[str, str]:
        """Save a new credential's hash and return the stored record."""
        record = {
            **self._key(credentials.key_id),
            "secret_hash": hash_api_key_secret(credentials.secret),
            "created_at": datetime.now(UTC).isoformat(),
        }
        # Let DynamoDB check this during the write so a duplicate ID cannot replace a key.
        self.table.put_item(Item=record, ConditionExpression="attribute_not_exists(pk)")
        return record

    def get(self, key_id: str) -> dict[str, str] | None:
        """Return the credential record, or None if it does not exist."""
        response = self.table.get_item(
            Key=self._key(key_id),
            # Read the latest state so a completed revocation takes effect on the next check.
            ConsistentRead=True,
        )
        return response.get("Item")

    def revoke(self, key_id: str) -> bool:
        """Remove the credential and report whether it existed."""
        response = self.table.delete_item(Key=self._key(key_id), ReturnValues="ALL_OLD")
        return bool(response.get("Attributes"))
