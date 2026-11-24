<?php

namespace App\Billing;

use App\Services\Pixelforge\PricingTable;
use App\Support\Money;

/**
 * Every price in the app comes from here: estimates before submitting, and the
 * exact cost once Pixelforge reports token usage.
 */
class PriceCalculator
{
    public const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public function __construct(private readonly int $markupPercent) {}

    /**
     * Exactly what Pixelforge bills for a usage report at the generation's own rates
     * (dollars per 1M tokens). Pixelforge never rounds, and with its two-decimal rates
     * every result is a whole number of nanodollars; anything finer is rounded half-up.
     *
     * @param  array<string, int>  $usage
     * @param  array<string, string>  $rates
     */
    public function providerCost(array $usage, array $rates): Money
    {
        // Sum of tokens x (nanodollars per 1M tokens), then one division at the end.
        $nanosPerMillionTokens = 0;

        foreach (self::TOKEN_TYPES as $type) {
            $tokens = (int) ($usage[$type] ?? 0);
            $rateNanos = Money::fromDecimal((string) ($rates[$type] ?? '0'))->nanos;
            $nanosPerMillionTokens += $tokens * $rateNanos;
        }

        return Money::nanos(intdiv($nanosPerMillionTokens + 500_000, 1_000_000));
    }

    /** What the user pays for a given Pixelforge cost: cost plus the markup. */
    public function userPrice(Money $providerCost): Money
    {
        return $providerCost->withMarkup($this->markupPercent);
    }

    /**
     * Up-front estimate. Output and image tokens are exact; text tokens are within
     * the published variance of ceil(characters / 4); reasoning tokens fall in a
     * per-quality range. We quote the middle and hold the worst case.
     */
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
