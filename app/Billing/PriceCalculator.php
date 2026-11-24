<?php

namespace App\Billing;

use App\Services\Pixelforge\PricingTable;
use App\Support\Money;

class PriceCalculator
{
    public const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public function __construct(private readonly int $markupPercent) {}

    /**
     * Rates are USD per 1M tokens. Use the rates stored on the generation,
     * not current pricing.
     *
     * @param  array<string, int>  $usage
     * @param  array<string, string>  $rates
     */
    public function providerCost(array $usage, array $rates): Money
    {
        $nanosPerMillionTokens = 0;

        foreach (self::TOKEN_TYPES as $type) {
            $tokens = (int) ($usage[$type] ?? 0);
            $rateNanos = Money::fromDecimal((string) ($rates[$type] ?? '0'))->nanos;
            $nanosPerMillionTokens += $tokens * $rateNanos;
        }

        return Money::nanos(intdiv($nanosPerMillionTokens + 500_000, 1_000_000));
    }

    public function userPrice(Money $providerCost): Money
    {
        return $providerCost->withMarkup($this->markupPercent);
    }

    // Text and reasoning tokens are only known afterwards, so: low / mid / max.
    public function estimate(PricingTable $pricing, string $prompt, string $size, string $quality, int $referenceImages): Estimate
    {
        $characters = mb_strlen($prompt);
        $textTokens = intdiv($characters + $pricing->charactersPerTextToken - 1, $pricing->charactersPerTextToken);
        $variance = $pricing->textVarianceBasisPoints;

        $textMin = intdiv($textTokens * (10_000 - $variance), 10_000);
        $textMax = intdiv($textTokens * (10_000 + $variance) + 9_999, 10_000); // ceil

        $imageTokens = $referenceImages * $pricing->tokensPerReferenceImage;
        $outputTokens = $pricing->outputTokensFor($quality, $size);
        $reasoning = $pricing->reasoningRangeFor($quality);
        $reasoningMid = intdiv($reasoning['min'] + $reasoning['max'], 2);

        $price = fn (int $text, int $reasoningTokens) => $this->userPrice($this->providerCost([
            'input_text_tokens' => $text,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoningTokens,
        ], $pricing->rates));

        return new Estimate(
            textTokens: $textTokens,
            textTokensMin: $textMin,
            textTokensMax: $textMax,
            imageTokens: $imageTokens,
            outputTokens: $outputTokens,
            reasoningTokensMin: $reasoning['min'],
            reasoningTokensMax: $reasoning['max'],
            low: $price($textMin, $reasoning['min']),
            expected: $price($textTokens, $reasoningMid),
            max: $price($textMax, $reasoning['max']),
            rates: $pricing->rates,
        );
    }
}
