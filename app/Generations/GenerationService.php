<?php

namespace App\Generations;

use App\Billing\CreditLedger;
use App\Billing\InsufficientCredits;
use App\Billing\PriceCalculator;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Webhooks, polling and the submit job all end up in applyRemoteState(),
 * so status changes and billing happen in one place.
 */
class GenerationService
{
    public function __construct(
        private readonly PixelforgeClient $pixelforge,
        private readonly PriceCalculator $prices,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * @param  list<string>  $referenceImages
     *
     * @throws InsufficientCredits
     */
    public function start(User $user, string $prompt, string $size, string $quality, array $referenceImages): Generation
    {
        $estimate = $this->prices->estimate($this->pixelforge->pricing(), $prompt, $size, $quality, count($referenceImages));

        $generation = DB::transaction(function () use ($user, $prompt, $size, $quality, $referenceImages, $estimate) {
            $balance = Money::nanos(User::whereKey($user->id)->lockForUpdate()->value('balance_nanos'));

            if ($balance->lessThan($estimate->max)) {
                throw new InsufficientCredits($estimate->max, $balance);
            }

            $generation = $user->generations()->create([
                'status' => GenerationStatus::Submitting,
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => array_values($referenceImages),
                'estimated_cost_nanos' => $estimate->expected->nanos,
                'max_cost_nanos' => $estimate->max->nanos,
                'rates' => $estimate->rates,
            ]);

            $this->ledger->hold($generation);

            return $generation;
        });

        SubmitGeneration::dispatch($generation->id)->afterCommit();

        return $generation;
    }

    // Idempotent: safe to call repeatedly and in any order.
    public function applyRemoteState(Generation $generation, array $remote): Generation
    {
        return DB::transaction(function () use ($generation, $remote) {
            /** @var Generation $current */
            $current = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();

            $current->pixelforge_id ??= $remote['id'] ?? null;
            $current->last_synced_at = now();

            $incoming = GenerationStatus::tryFrom($remote['status'] ?? '');

            if ($incoming === null || ! $current->status->canMoveTo($incoming)) {
                // stale or duplicate event
                $current->save();

                return $current;
            }

            $current->fill([
                'status' => $incoming,
                'retry_reason' => null,
                'rates' => $remote['rates'] ?? $current->rates,
                'provider_created_at' => $this->time($remote['created_at'] ?? null) ?? $current->provider_created_at,
                'started_at' => $this->time($remote['started_at'] ?? null) ?? $current->started_at,
                'completed_at' => $this->time($remote['completed_at'] ?? null) ?? $current->completed_at,
            ]);

            match ($incoming) {
                GenerationStatus::Succeeded => $this->recordSuccess($current, $remote),
                GenerationStatus::Failed => $this->recordFailure($current, $remote['error']['code'] ?? 'generation_failed', $remote['error']['message'] ?? 'The generation failed.'),
                default => $current->save(),
            };

            return $current;
        });
    }

    public function failBeforeAcceptance(Generation $generation, string $code, string $message): void
    {
        DB::transaction(function () use ($generation, $code, $message) {
            $current = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if ($current->status->isFinal()) {
                return;
            }

            $current->status = GenerationStatus::Failed;
            $current->completed_at = now();
            $this->recordFailure($current, $code, $message);
        });
    }

    private function recordSuccess(Generation $generation, array $remote): void
    {
        $usage = array_intersect_key($remote['usage'] ?? [], array_flip([...PriceCalculator::TOKEN_TYPES, 'total_tokens']));
        $providerCost = $this->prices->providerCost($usage, $generation->rates ?? []);
        $charge = $this->prices->userPrice($providerCost);

        $generation->fill([
            'usage' => $usage,
            'provider_cost_nanos' => $providerCost->nanos,
            'charged_nanos' => $charge->nanos,
            'image_url' => $remote['image_url'] ?? null,
            'image_url_expires_at' => $this->time($remote['image_url_expires_at'] ?? null),
            'error_code' => null,
            'error_message' => null,
        ])->save();

        $this->ledger->settle($generation, $charge);

        if ($charge->greaterThan($generation->maxCost())) {
            Log::warning('Generation cost exceeded its hold.', ['generation' => $generation->id, 'charge' => $charge->toDecimal()]);
        }
    }

    private function recordFailure(Generation $generation, string $code, string $message): void
    {
        $generation->fill([
            'error_code' => $code,
            'error_message' => $message,
            'retry_reason' => null,
            'provider_cost_nanos' => 0,
            'charged_nanos' => 0,
        ])->save();

        $this->ledger->release($generation);
    }

    private function time(?string $value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::parse($value)->utc() : null;
    }
}
