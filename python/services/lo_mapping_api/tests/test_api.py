import json

import pytest
from fastapi.testclient import TestClient

from app.handler import handler
from app.main import app


def test_health_returns_json_over_http():
    with TestClient(app) as client:
        response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {"status": "ok"}


@pytest.mark.parametrize(
    ("path", "expected_status", "expected_body"),
    [
        ("/health", 200, {"status": "ok"}),
        ("/missing", 404, {"detail": "Not Found"}),
    ],
)
def test_lambda_routes_http_api_requests(path, expected_status, expected_body):
    # Use the event shape API Gateway sends, so this checks Mangum too.
    event = {
        "version": "2.0",
        "routeKey": "$default",
        "rawPath": path,
        "rawQueryString": "",
        "headers": {
            "host": "example.execute-api.ca-central-1.amazonaws.com",
            "x-forwarded-proto": "https",
        },
        "requestContext": {
            "stage": "$default",
            "http": {
                "method": "GET",
                "path": path,
                "sourceIp": "127.0.0.1",
                "protocol": "HTTP/1.1",
            },
        },
        "isBase64Encoded": False,
    }

    response = handler(event, None)

    assert response["statusCode"] == expected_status
    assert json.loads(response["body"]) == expected_body
