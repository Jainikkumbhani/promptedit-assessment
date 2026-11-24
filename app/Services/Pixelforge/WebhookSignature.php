<?php

namespace App\Services\Pixelforge;

/**
 * Verifies "Pixelforge-Signature: t=<unix>,v1=<hex hmac>", where the HMAC-SHA256
 * is over "<t>.<raw body>" with the key's signing secret.
 */
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

        // Old (or far-future) timestamps are rejected so a captured request can't be replayed.
        if (abs(($now ?? time()) - $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $this->secret);

        return hash_equals($expected, $signature);
    }

    /** Builds a header the way Pixelforge does. Used by tests. */
    public function sign(string $rawBody, int $timestamp): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$rawBody}", (string) $this->secret);
    }
}
