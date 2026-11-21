"""Run with python -m app.manage_api_keys from the mapping API directory."""

import argparse
import sys

from botocore.exceptions import BotoCoreError, ClientError
from pydantic import ValidationError

from app.api_key_store import ApiKeyStore
from app.api_keys import generate_api_key


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="Create or revoke API keys in the configured DynamoDB table.",
        epilog=(
            "Run from python/services/lo_mapping_api with your AWS login configured. "
            "Set AWS_REGION and LO_MAPPING_DYNAMODB_TABLE in the environment or .env."
        ),
    )
    commands = parser.add_subparsers(dest="command", required=True)
    commands.add_parser(
        "create",
        help="Create a key and display its credentials once.",
        description="Save a new key's hash, then display its ID and original secret.",
    )
    revoke_parser = commands.add_parser(
        "revoke",
        help="Delete an API key's stored credential.",
        description="Delete the stored credential for the given API key ID.",
    )
    revoke_parser.add_argument("key_id", help="The ID of the API key to revoke.")
    args = parser.parse_args(argv)
    if args.command == "revoke" and not args.key_id.strip():
        parser.error("API key ID must not be blank.")

    try:
        # Parse help and invalid arguments before loading AWS settings or credentials.
        store = ApiKeyStore()
        if args.command == "create":
            credentials = generate_api_key()
            store.create(credentials)
            # Only show credentials once DynamoDB has accepted their stored hash.
            print(f"X-API-Key-ID: {credentials.key_id}")
            print(f"X-API-Key-Secret: {credentials.secret}")
            print(
                "Save these credentials in Laravel's protected server configuration. "
                "The original secret cannot be retrieved later.",
                file=sys.stderr,
            )
        elif store.revoke(args.key_id):
            print(f"Revoked API key {args.key_id}.")
        else:
            print(f"API key {args.key_id} was not found; no changes made.")
    except ValidationError:
        print(
            "Invalid configuration. Set nonblank AWS_REGION and LO_MAPPING_DYNAMODB_TABLE.",
            file=sys.stderr,
        )
        return 1
    except ClientError as error:
        # Keep raw AWS error messages out of the output; they may contain request details.
        if error.response["Error"]["Code"] == "ConditionalCheckFailedException":
            message = "This API key ID already exists. Run create again to generate a new one."
        else:
            message = "DynamoDB rejected the request. Check your AWS permissions and table configuration."
        print(message, file=sys.stderr)
        return 1
    except BotoCoreError:
        print(
            "Unable to complete the AWS request. Check your AWS login, configuration and connection.",
            file=sys.stderr,
        )
        return 1

    return 0


if __name__ == "__main__":
    sys.exit(main())
