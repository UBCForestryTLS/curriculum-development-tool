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

    // Extracts text and topics
    'text_extraction' => [
        'base_url' => env('TEXT_EXTRACTION_SERVICE_URL', 'http://127.0.0.1:8003'),
    ],

    // When running on Windows, the php server has trouble finding pdftoppm.exe.
    // If pdftoppm is installed in WSL, macOS, or Linux, we
    // don't need to add the env var, it just defaults to pdftoppm.
    'pdftoppm' => ['path' => env('PDFTOPPM_PATH', 'pdftoppm')],

];
