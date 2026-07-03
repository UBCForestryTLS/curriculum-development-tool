<?php

namespace Tests\Helpers\AiSuggestionsE2E;

use RuntimeException;

function ensureLOMappingServiceReachable(): void
{
    $baseUrl = getenv('LO_MAPPING_SERVICE_URL') ?: 'http://127.0.0.1:8002';
    $ch = curl_init(rtrim($baseUrl, '/') . '/docs');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 500) {
        throw new RuntimeException(
            "FastAPI (lo_mapping_service) is not reachable at $baseUrl.\n" .
            "Start it from python/services/lo_mapping_service/ with:\n" .
            "    python -m app.test"
        );
    }
}
