<?php

namespace App\Services\Pixelforge;

// Pixelforge-Signature: t=<unix>,v1=hmac_sha256("<t>.<body>", secret)
class WebhookSignature
{
    public function __construct(
        private readonly ?string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {}

    public function isValid(string $rawBody, ?string $header, ?int $now = null): bool
    {
        if (blank($this->secret) || blank($header)) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = ctype_digit($parts['t'] ?? '') ? (int) $parts['t'] : null;
        $signature = $parts['v1'] ?? '';

        if ($timestamp === null || $signature === '') {
            return false;
        }

        // replay protection
        if (abs(($now ?? time()) - $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $this->secret);

        return hash_equals($expected, $signature);
    }

    // used in tests
    public function sign(string $rawBody, int $timestamp): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$rawBody}", (string) $this->secret);
    }
}
