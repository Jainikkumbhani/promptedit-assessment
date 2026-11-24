<?php

namespace App\Services\Pixelforge;

use RuntimeException;

class PixelforgeException extends RuntimeException
{
    private const RETRYABLE = [
        'rate_limit_exceeded',
        'concurrency_limit_exceeded',
        'overloaded',
        'network_error',
        'server_error',
    ];

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 0,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public function isRetryable(): bool
    {
        return in_array($this->errorCode, self::RETRYABLE, true);
    }
}
