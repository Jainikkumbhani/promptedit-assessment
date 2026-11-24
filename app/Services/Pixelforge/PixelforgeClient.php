<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout = 15,
    ) {}

    public function pricing(): PricingTable
    {
        $data = Cache::remember('pixelforge:pricing', now()->addMinutes(5), function () {
            return $this->send(fn () => $this->http(authenticated: false)->get('/pricing'));
        });

        return PricingTable::fromApi($data);
    }

    public function createGeneration(array $params, string $idempotencyKey): array
    {
        return $this->send(fn () => $this->http()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', $params));
    }

    public function getGeneration(string $pixelforgeId): array
    {
        return $this->send(fn () => $this->http()->get('/generations/'.rawurlencode($pixelforgeId)));
    }

    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn () => $this->http()->get('/usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    private function http(bool $authenticated = true): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout(5);

        if ($authenticated) {
            if (blank($this->apiKey)) {
                throw new PixelforgeException('invalid_api_key', 'PIXELFORGE_API_KEY is not configured.', 401);
            }

            $request->withToken($this->apiKey);
        }

        return $request;
    }

    /** @param  callable(): Response  $call */
    private function send(callable $call): array
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new PixelforgeException('network_error', 'Could not reach Pixelforge: '.$e->getMessage());
        }

        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $error = $response->json('error') ?? [];
        $retryAfter = $response->header('Retry-After');

        throw new PixelforgeException(
            errorCode: $error['code'] ?? ($response->serverError() ? 'server_error' : 'http_'.$response->status()),
            message: $error['message'] ?? "Pixelforge returned HTTP {$response->status()}.",
            status: $response->status(),
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }
}
