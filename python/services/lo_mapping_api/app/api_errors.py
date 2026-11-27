from botocore.exceptions import (
    ClientError,
    ConnectionClosedError,
    ConnectTimeoutError,
    EndpointConnectionError,
    ReadTimeoutError,
)
from fastapi import HTTPException, status


def _is_retryable_aws_error(error: Exception) -> bool:
    """Recognize temporary AWS failures; other errors need attention before retrying."""
    if isinstance(error, (ConnectionClosedError, ConnectTimeoutError, EndpointConnectionError, ReadTimeoutError)):
        return True
    if not isinstance(error, ClientError):
        # Missing settings or credentials will not be fixed by sending the request again.
        return False

    code = error.response.get("Error", {}).get("Code")
    if code == "TransactionCanceledException":
        # A cancelled save can mean throttling or a failed condition. Only retry temporary causes.
        reasons = {
            reason.get("Code") for reason in error.response.get("CancellationReasons", [])
            if reason.get("Code") != "None"
        }
        return bool(reasons) and reasons.issubset({
            "TransactionConflict", "ProvisionedThroughputExceeded", "ThrottlingError",
        })

    http_status = error.response.get("ResponseMetadata", {}).get("HTTPStatusCode")
    return http_status in (408, 429, 500, 502, 503, 504) or code in {
        "ThrottlingException",
        "ProvisionedThroughputExceededException",
        "RequestLimitExceeded",
        "TransactionConflictException",
        "InternalServerError",
        "InternalServerException",
        "ServiceUnavailable",
        "ServiceUnavailableException",
        "ModelNotReadyException",
        "ModelTimeoutException",
    }


def service_unavailable(error: Exception, *, message: str) -> HTTPException:
    """Return a safe service error with an action Laravel can use for bounded retries."""
    # Keep AWS messages out of the response. Laravel should use action, not parse the message.
    # This only describes what to do; the future worker will handle retry limits and delays.
    action = "retry" if _is_retryable_aws_error(error) else "stop"
    return HTTPException(
        status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
        detail={"message": message, "action": action},
    )
