<?php

use App\Billing\CreditLedger;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\WebhookSignature;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/*
| Fixtures copied from the Pixelforge API reference.
*/

function pixelforgePricing(): array
{
    return [
        'object' => 'pricing',
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];
}

/** A Pixelforge generation object, overridable per test. */
function pixelforgeGeneration(array $overrides = []): array
{
    return array_replace([
        'id' => 'gen_'.Str::upper(Str::random(26)),
        'object' => 'generation',
        'status' => 'queued',
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => [],
        'metadata' => [],
        'image_url' => null,
        'image_url_expires_at' => null,
        'usage' => null,
        'rates' => pixelforgePricing()['rates'],
        'error' => null,
        'created_at' => '2026-11-04T14:03:11Z',
        'started_at' => null,
        'completed_at' => null,
    ], $overrides);
}

function succeededGeneration(string $pixelforgeId, array $overrides = []): array
{
    return pixelforgeGeneration(array_replace([
        'id' => $pixelforgeId,
        'status' => 'succeeded',
        'image_url' => "https://pixelforge.promptedit.dev/images/{$pixelforgeId}.png?expires=1&signature=x",
        'image_url_expires_at' => now()->addDay()->toIso8601String(),
        // The succeeded example from the docs: costs exactly $0.305510000.
        'usage' => [
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'total_tokens' => 10411,
        ],
        'started_at' => '2026-11-04T14:03:13Z',
        'completed_at' => '2026-11-04T14:03:29Z',
    ], $overrides));
}

function fakePixelforge(array $routes = []): void
{
    config(['services.pixelforge.api_key' => 'pf_test_key', 'services.pixelforge.webhook_secret' => 'whsec_test']);

    Http::preventStrayRequests();
    Http::fake([
        ...$routes,
        'pixelforge.promptedit.dev/v1/pricing' => Http::response(pixelforgePricing()),
    ]);
}

function userWithCredits(string $dollars = '10.00'): User
{
    $user = User::factory()->create();
    app(CreditLedger::class)->purchase($user, Money::fromDecimal($dollars));

    return $user->fresh();
}

/** A generation Pixelforge has accepted, with its worst case held from the user's balance. */
function acceptedGeneration(User $user, array $attributes = []): Generation
{
    $generation = $user->generations()->create(array_replace([
        'status' => GenerationStatus::Queued,
        'pixelforge_id' => 'gen_'.Str::upper(Str::random(26)),
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/style.png'],
        'estimated_cost_nanos' => 300_000_000,
        'max_cost_nanos' => 500_000_000,
        'rates' => pixelforgePricing()['rates'],
    ], $attributes));

    app(CreditLedger::class)->hold($generation);

    return $generation->fresh();
}

function webhookEvent(string $type, array $generation, ?string $eventId = null): array
{
    return [
        'id' => $eventId ?? 'evt_'.Str::upper(Str::random(26)),
        'object' => 'event',
        'type' => $type,
        'created_at' => now()->toIso8601String(),
        'data' => ['object' => $generation],
    ];
}

/** POST a webhook signed exactly the way Pixelforge signs it. */
function sendWebhook(array $event, ?string $signature = null): TestResponse
{
    $body = json_encode($event);
    $signature ??= app(WebhookSignature::class)->sign($body, time());

    return test()->call('POST', '/webhooks/pixelforge', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $signature,
        'HTTP_PIXELFORGE_EVENT_ID' => $event['id'],
        'HTTP_PIXELFORGE_EVENT_TYPE' => $event['type'],
    ], $body);
}
