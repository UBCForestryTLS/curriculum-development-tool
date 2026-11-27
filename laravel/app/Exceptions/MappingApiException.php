<?php

namespace App\Exceptions;

use RuntimeException;

class MappingApiException extends RuntimeException
{
    /** Describe the failure so the worker can decide whether to retry, wait or stop. */
    public function __construct(
        string $message,
        public readonly string $action = 'stop',
        public readonly ?int $httpStatus = null,
    ) {
        // Keep raw HTTP requests and responses out of exceptions; they can contain credentials or outcome text.
        parent::__construct($message);
    }
}
