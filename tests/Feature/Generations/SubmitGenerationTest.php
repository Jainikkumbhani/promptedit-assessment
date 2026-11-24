<?php

use App\Enums\GenerationStatus;
use App\Generations\GenerationService;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function submittingGeneration(): Generation
{
    return acceptedGeneration(userWithCredits('1.00'), [
        'status' => GenerationStatus::Submitting,
        'pixelforge_id' => null,
    ]);
}

it('sends the generation with our id as the idempotency key and metadata', function () {
    fakePixelforge(['pixelforge.promptedit.dev/v1/generations' => Http::response(pixelforgeGeneration(['id' => 'gen_ABC']), 202)]);
    $generation = submittingGeneration();

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationService::class));

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Queued)
        ->pixelforge_id->toBe('gen_ABC');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://pixelforge.promptedit.dev/v1/generations'
        && $request->header('Idempotency-Key')[0] === $generation->id
        && $request['metadata'] === ['generation_id' => $generation->id]
        && $request['reference_images'] === ['https://example.com/style.png']
        && ! array_key_exists('rates', $request->data()));
});

it('waits and retries when Pixelforge is overloaded or limited', function (string $code, int $retryAfter) {
    fakePixelforge(['pixelforge.promptedit.dev/v1/generations' => Http::response(
        ['error' => ['type' => 'rate_limit_error', 'code' => $code, 'message' => 'Slow down']], $code === 'overloaded' ? 503 : 429, ['Retry-After' => (string) $retryAfter],
    )]);
    $generation = submittingGeneration();

    $job = (new SubmitGeneration($generation->id))->withFakeQueueInteractions();
    $job->handle(app(PixelforgeClient::class), app(GenerationService::class));

    $job->assertReleased();
    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Submitting)
        ->retry_reason->toBe($code);
})->with([
    ['overloaded', 2],
    ['rate_limit_exceeded', 10],
    ['concurrency_limit_exceeded', 5],
]);

it('fails without charging when the prompt is blocked', function () {
    fakePixelforge(['pixelforge.promptedit.dev/v1/generations' => Http::response(
        ['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'Rejected']], 400,
    )]);
    $generation = submittingGeneration();

    $job = (new SubmitGeneration($generation->id))->withFakeQueueInteractions();
    $job->handle(app(PixelforgeClient::class), app(GenerationService::class));

    $job->assertNotReleased();
    $fresh = $generation->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Failed)
        ->and($fresh->error_code)->toBe('content_policy_violation')
        ->and($fresh->charged_nanos)->toBe(0)
        ->and($fresh->user->balance_nanos)->toBe(1_000_000_000);
});

it('does nothing if a webhook already moved the generation on', function () {
    fakePixelforge();
    $generation = submittingGeneration();
    $generation->update(['status' => GenerationStatus::Processing, 'pixelforge_id' => 'gen_X']);

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationService::class));

    Http::assertNothingSent();
});
