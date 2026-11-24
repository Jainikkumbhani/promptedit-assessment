<?php

use App\Billing\PriceCalculator;
use App\Services\Pixelforge\PricingTable;

beforeEach(function () {
    $this->prices = new PriceCalculator(markupPercent: 25);
    $this->rates = pixelforgePricing()['rates'];
});

it('matches the succeeded generation in the docs', function () {
    $cost = $this->prices->providerCost([
        'input_text_tokens' => 12,
        'input_image_tokens' => 765,
        'output_tokens' => 6450,
        'reasoning_tokens' => 3184,
    ], $this->rates);

    expect($cost->toDecimal())->toBe('0.305510000')
        ->and($this->prices->userPrice($cost)->toDecimal())->toBe('0.381887500');
});

it('matches the usage report example in the docs', function () {
    $cost = $this->prices->providerCost([
        'input_text_tokens' => 41,
        'input_image_tokens' => 0,
        'output_tokens' => 3300,
        'reasoning_tokens' => 2950,
    ], $this->rates);

    expect($cost->toDecimal())->toBe('0.169080000');
});

it('reproduces the worst case worked example in the docs', function () {
    $estimate = $this->prices->estimate(
        PricingTable::fromApi(pixelforgePricing()),
        prompt: str_repeat('a', 400),
        size: '1536x1024',
        quality: 'high',
        referenceImages: 2,
    );

    // Pixelforge's maximum is $0.373875; users pay 25% more.
    expect($estimate->textTokens)->toBe(100)
        ->and($estimate->textTokensMax)->toBe(115)
        ->and($estimate->imageTokens)->toBe(1530)
        ->and($estimate->outputTokens)->toBe(6450)
        ->and($estimate->max->toDecimal())->toBe('0.467343750')
        ->and($estimate->low->nanos)->toBeLessThan($estimate->expected->nanos)
        ->and($estimate->expected->nanos)->toBeLessThan($estimate->max->nanos);
});

it('counts unicode characters, not bytes', function () {
    $estimate = $this->prices->estimate(PricingTable::fromApi(pixelforgePricing()), 'éééé', '1024x1024', 'low', 0);

    expect($estimate->textTokens)->toBe(1);
});

it('uses the rates the generation was created at', function () {
    $doubled = array_map(fn () => '80.00', $this->rates);

    expect($this->prices->providerCost(['output_tokens' => 1000], $doubled)->toDecimal())->toBe('0.080000000');
});
