<?php

namespace App\Billing;

use App\Support\Money;

final readonly class Estimate
{
    /** @param  array<string, string>  $rates */
    public function __construct(
        public int $textTokens,
        public int $textTokensMin,
        public int $textTokensMax,
        public int $imageTokens,
        public int $outputTokens,
        public int $reasoningTokensMin,
        public int $reasoningTokensMax,
        public Money $low,
        public Money $expected,
        public Money $max,
        public array $rates,
    ) {}

    public function toArray(): array
    {
        return [
            'low_nanos' => $this->low->nanos,
            'expected_nanos' => $this->expected->nanos,
            'max_nanos' => $this->max->nanos,
            'tokens' => [
                'input_text' => ['estimate' => $this->textTokens, 'min' => $this->textTokensMin, 'max' => $this->textTokensMax],
                'input_image' => $this->imageTokens,
                'output' => $this->outputTokens,
                'reasoning' => ['min' => $this->reasoningTokensMin, 'max' => $this->reasoningTokensMax],
            ],
            'rates' => $this->rates,
        ];
    }
}
