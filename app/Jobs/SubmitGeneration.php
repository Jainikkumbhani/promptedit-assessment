<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Generations\GenerationService;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * 429/503 -> retry after Retry-After with backoff, give up after MAX_ATTEMPTS.
 * Our id is the Idempotency-Key, so retries can't create a second generation.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 8;

    public int $tries = self::MAX_ATTEMPTS;

    public function __construct(public readonly string $generationId) {}

    public function handle(PixelforgeClient $pixelforge, GenerationService $generations): void
    {
        $generation = Generation::find($this->generationId);

        if (! $generation || $generation->status !== GenerationStatus::Submitting) {
            return; // a webhook may have got here first
        }

        $generation->increment('submit_attempts');

        try {
            $remote = $pixelforge->createGeneration(array_filter([
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_images ?: null,
                'metadata' => ['generation_id' => $generation->id],
            ], fn ($value) => $value !== null), idempotencyKey: $generation->id);

            $generations->applyRemoteState($generation, $remote);
        } catch (PixelforgeException $e) {
            if ($e->isRetryable() && $this->attempts() < self::MAX_ATTEMPTS) {
                $generation->update(['retry_reason' => $e->errorCode]);
                $this->release($this->backoffSeconds($e));

                return;
            }

            $generations->failBeforeAcceptance($generation, $e->errorCode, $this->explain($e));
        }
    }

    public function failed(?Throwable $e): void
    {
        $generation = Generation::find($this->generationId);

        if ($generation) {
            app(GenerationService::class)->failBeforeAcceptance(
                $generation,
                'submission_error',
                'We could not send this generation to Pixelforge.',
            );
        }
    }

    private function backoffSeconds(PixelforgeException $e): int
    {
        $exponential = min(60, 2 ** $this->attempts());

        return max($e->retryAfter ?? 1, $exponential) + random_int(0, 2);
    }

    private function explain(PixelforgeException $e): string
    {
        return match ($e->errorCode) {
            'content_policy_violation' => 'Pixelforge rejected this prompt under its content policy.',
            'daily_limit_exceeded' => "Pixelforge's daily generation limit is used up. It resets at midnight UTC.",
            'rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded', 'server_error', 'network_error' => 'Pixelforge stayed busy after several retries, so we stopped trying.',
            'invalid_api_key' => 'The image service is not configured correctly.',
            default => $e->getMessage(),
        };
    }
}
