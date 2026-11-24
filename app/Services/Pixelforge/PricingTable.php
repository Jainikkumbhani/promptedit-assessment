<?php

namespace App\Services\Pixelforge;

use InvalidArgumentException;

/**
 * GET /v1/pricing, as a typed object. Sizes and qualities are read from the
 * output-token table, so the UI offers exactly what Pixelforge supports.
 */
final readonly class PricingTable
{
    /**
     * @param  array<string, string>  $rates  dollars per 1M tokens, keyed by token type
     * @param  array<string, array<string, int>>  $outputTokens  [quality][size] => tokens
     * @param  array<string, array{min:int, max:int}>  $reasoning  [quality] => range
     */
    public function __construct(
        public array $rates,
        public int $charactersPerTextToken,
        public int $textVarianceBasisPoints,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoning,
    ) {}

    public static function fromApi(array $data): self
    {
        $tokens = $data['tokens'];

        return new self(
            rates: array_map('strval', $data['rates']),
            charactersPerTextToken: (int) $tokens['characters_per_text_token'],
            // 0.15 -> 1500 basis points, so the rest of the maths stays in integers.
            textVarianceBasisPoints: (int) round(((float) $tokens['text_token_variance']) * 10_000),
            tokensPerReferenceImage: (int) $tokens['per_reference_image'],
            outputTokens: $tokens['output'],
            reasoning: $tokens['reasoning'],
        );
    }

    /** @return list<string> */
    public function qualities(): array
    {
        return array_keys($this->outputTokens);
    }

    /** @return list<string> */
    public function sizes(): array
    {
        return array_keys(array_values($this->outputTokens)[0] ?? []);
    }

    public function outputTokensFor(string $quality, string $size): int
    {
        return $this->outputTokens[$quality][$size]
            ?? throw new InvalidArgumentException("Unknown quality/size [{$quality} {$size}].");
    }

    /** @return array{min:int, max:int} */
    public function reasoningRangeFor(string $quality): array
    {
        return $this->reasoning[$quality]
            ?? throw new InvalidArgumentException("Unknown quality [{$quality}].");
    }

    public function toArray(): array
    {
        return [
            'rates' => $this->rates,
            'sizes' => $this->sizes(),
            'qualities' => $this->qualities(),
            'output_tokens' => $this->outputTokens,
            'reasoning' => $this->reasoning,
            'tokens_per_reference_image' => $this->tokensPerReferenceImage,
        ];
    }
}
