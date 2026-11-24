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
 * Gets Pixelforge to accept a generation.
 *
 * Rate limits, the concurrency limit and overloads mean "nothing was created, try
 * later": we wait at least Retry-After, back off further each time, and give up
 * after MAX_ATTEMPTS. Our generation id is the Idempotency-Key, so a retry after a
 * timeout can never create a second (billable) generation.
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
            return; // already accepted (maybe a webhook beat us here) or already failed
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

    /** Called by the queue if the job crashed for an unexpected reason too many times. */
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

    /** At least Retry-After, growing exponentially (2, 4, 8, ... capped at 60s), plus jitter. */
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
