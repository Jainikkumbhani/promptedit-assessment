<?php

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\WebhookEvent;

beforeEach(function () {
    fakePixelforge();
    $this->user = userWithCredits('1.00');
    $this->generation = acceptedGeneration($this->user);
});

it('rejects requests with a bad signature', function () {
    $event = webhookEvent('generation.succeeded', succeededGeneration($this->generation->pixelforge_id));

    sendWebhook($event, signature: 't='.time().',v1=deadbeef')->assertStatus(400);

    expect(WebhookEvent::count())->toBe(0)
        ->and($this->generation->fresh()->status)->toBe(GenerationStatus::Queued);
});

it('charges the exact cost plus markup when a generation succeeds', function () {
    sendWebhook(webhookEvent('generation.succeeded', succeededGeneration($this->generation->pixelforge_id)))->assertNoContent();

    $generation = $this->generation->fresh();

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost_nanos)->toBe(305_510_000)   // $0.305510000 from the docs
        ->and($generation->charged_nanos)->toBe(381_887_500)         // + 25%
        ->and($generation->image_url)->not->toBeNull()
        ->and($generation->completed_at->toIso8601String())->toBe('2026-11-04T14:03:29+00:00')
        ->and($this->user->fresh()->balance_nanos)->toBe(1_000_000_000 - 381_887_500);
});

it('never records the same event twice', function () {
    $event = webhookEvent('generation.succeeded', succeededGeneration($this->generation->pixelforge_id));

    sendWebhook($event)->assertNoContent();
    sendWebhook($event)->assertNoContent();
    sendWebhook($event)->assertNoContent();

    expect(WebhookEvent::count())->toBe(1)
        ->and(CreditTransaction::where('generation_id', $this->generation->id)->where('type', CreditTransactionType::Charge)->count())->toBe(1)
        ->and($this->user->fresh()->balance_nanos)->toBe(1_000_000_000 - 381_887_500);
});

it('charges once even if two different events both report success', function () {
    sendWebhook(webhookEvent('generation.succeeded', succeededGeneration($this->generation->pixelforge_id)));
    sendWebhook(webhookEvent('generation.succeeded', succeededGeneration($this->generation->pixelforge_id)));

    expect(CreditTransaction::where('generation_id', $this->generation->id)->where('type', CreditTransactionType::Charge)->count())->toBe(1);
});

it('ignores a late processing event after success', function () {
    $succeeded = succeededGeneration($this->generation->pixelforge_id);

    sendWebhook(webhookEvent('generation.succeeded', $succeeded));
    sendWebhook(webhookEvent('generation.processing', pixelforgeGeneration(['id' => $this->generation->pixelforge_id, 'status' => 'processing'])));

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('never moves a failed generation to succeeded', function () {
    sendWebhook(webhookEvent('generation.failed', pixelforgeGeneration([
        'id' => $this->generation->pixelforge_id, 'status' => 'failed',
        'error' => ['code' => 'generation_failed', 'message' => 'Could not draw it.'],
    ])));
    sendWebhook(webhookEvent('generation.succeeded', succeededGeneration($this->generation->pixelforge_id)));

    expect($this->generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->charged_nanos->toBe(0);
});

it('does not charge failed generations and releases the hold', function () {
    sendWebhook(webhookEvent('generation.failed', pixelforgeGeneration([
        'id' => $this->generation->pixelforge_id, 'status' => 'failed',
        'error' => ['code' => 'internal_error', 'message' => 'Something went wrong on our side.'],
    ])))->assertNoContent();

    $generation = $this->generation->fresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('internal_error')
        ->and($generation->charged_nanos)->toBe(0)
        ->and($this->user->fresh()->balance_nanos)->toBe(1_000_000_000);
});

it('handles a webhook that arrives before the create request returned', function () {
    $generation = acceptedGeneration($this->user, ['status' => GenerationStatus::Submitting, 'pixelforge_id' => null]);

    sendWebhook(webhookEvent('generation.processing', pixelforgeGeneration([
        'id' => 'gen_EARLY', 'status' => 'processing', 'metadata' => ['generation_id' => $generation->id],
    ])))->assertNoContent();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Processing)
        ->pixelforge_id->toBe('gen_EARLY');
});

it('acknowledges pings and unknown generations without failing', function () {
    sendWebhook(webhookEvent('ping', []))->assertNoContent();
    sendWebhook(webhookEvent('generation.succeeded', succeededGeneration('gen_SOMEONE_ELSE')))->assertNoContent();

    expect(WebhookEvent::pluck('outcome')->all())->toBe(['ignored', 'unknown_generation']);
});
