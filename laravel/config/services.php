<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'python_api' => [
        'base_url' => env('PYTHON_API_URL'),
    ],

    'lo_mapping' => [
        'base_url' => env('LO_MAPPING_SERVICE_URL', 'http://127.0.0.1:8002'),
    ],

    // New mapping API settings, separate from the existing service during the refactor.
    'lo_mapping_api' => [
        'base_url' => env('LO_MAPPING_API_URL'),
        'key_id' => env('LO_MAPPING_API_KEY_ID'),
        'key_secret' => env('LO_MAPPING_API_KEY_SECRET'),
        // Leave time to receive the gateway's response after its 30 second timeout.
        'timeout' => (int) env('LO_MAPPING_API_TIMEOUT', 35),
        'connect_timeout' => (int) env('LO_MAPPING_API_CONNECT_TIMEOUT', 5),
    ],

    // Extracts text and topics
    'text_extraction' => [
        'base_url' => env('TEXT_EXTRACTION_SERVICE_URL', 'http://127.0.0.1:8003'),
    ],

];
