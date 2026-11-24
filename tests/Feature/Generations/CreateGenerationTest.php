<?php

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => fakePixelforge());

it('holds the worst case and queues the submission', function () {
    Queue::fake();
    $user = userWithCredits('1.00');

    $this->actingAs($user)->post('/generations', [
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
    ])->assertSessionHasNoErrors();

    $generation = Generation::sole();

    expect($generation->status)->toBe(GenerationStatus::Submitting)
        ->and($generation->max_cost_nanos)->toBe(467_343_750)  // the docs' worst case + 25%
        ->and($user->fresh()->balance_nanos)->toBe(1_000_000_000 - 467_343_750)
        ->and($generation->rates)->toBe(pixelforgePricing()['rates']);

    Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generationId === $generation->id);
});

it('blocks generating when the user cannot afford the worst case', function () {
    Queue::fake();
    $user = userWithCredits('0.10');

    $this->actingAs($user)->post('/generations', [
        'prompt' => 'A castle', 'size' => '1536x1024', 'quality' => 'high',
    ])->assertSessionHasErrors('credits');

    expect(Generation::count())->toBe(0)
        ->and($user->fresh()->balance_nanos)->toBe(100_000_000);
    Queue::assertNothingPushed();
});

it('validates input the way Pixelforge does', function (array $input, string $field) {
    $this->actingAs(userWithCredits())
        ->post('/generations', array_replace(['prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low'], $input))
        ->assertSessionHasErrors($field);
})->with([
    'missing prompt' => [['prompt' => ''], 'prompt'],
    'prompt too long' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
    'unknown size' => [['size' => '512x512'], 'size'],
    'unknown quality' => [['quality' => 'ultra'], 'quality'],
    'too many references' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
    'non-http reference' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
]);

it('returns a live estimate', function () {
    $this->actingAs(userWithCredits())
        ->postJson('/generations/estimate', ['prompt' => str_repeat('a', 400), 'size' => '1536x1024', 'quality' => 'high', 'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png']])
        ->assertOk()
        ->assertJsonPath('max_nanos', 467_343_750)
        ->assertJsonPath('tokens.output', 6450);
});

it('gives new users their welcome credits once', function () {
    $this->post('/register', [
        'name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'password', 'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'ada@example.com')->sole();

    expect($user->balance_nanos)->toBe(2_000_000_000)
        ->and($user->creditTransactions()->where('type', CreditTransactionType::SignupBonus)->count())->toBe(1);
});
