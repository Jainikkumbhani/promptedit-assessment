<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;

/**
 * USD stored as integer nanodollars. Pixelforge bills with 9 decimal places,
 * so this is exact and avoids float drift.
 */
final readonly class Money implements JsonSerializable
{
    public const NANOS_PER_DOLLAR = 1_000_000_000;

    private function __construct(public int $nanos) {}

    public static function nanos(int $nanos): self
    {
        return new self($nanos);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    // Throws instead of rounding if there are more than 9 decimals.
    public static function fromDecimal(string $dollars): self
    {
        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,9}))?$/', trim($dollars), $m)) {
            throw new InvalidArgumentException("Not an exact dollar amount: [{$dollars}]");
        }

        $nanos = (int) $m[2] * self::NANOS_PER_DOLLAR + (int) str_pad($m[3] ?? '', 9, '0');

        return new self($m[1] === '-' ? -$nanos : $nanos);
    }

    public function plus(self $other): self
    {
        return new self($this->nanos + $other->nanos);
    }

    public function minus(self $other): self
    {
        return new self($this->nanos - $other->nanos);
    }

    public function negate(): self
    {
        return new self(-$this->nanos);
    }

    // Rounds up so we never undercharge (max 0.75 nanodollar).
    public function withMarkup(int $percent): self
    {
        $numerator = $this->nanos * (100 + $percent);

        return new self(intdiv($numerator + 99, 100));
    }

    public function isNegative(): bool
    {
        return $this->nanos < 0;
    }

    public function greaterThan(self $other): bool
    {
        return $this->nanos > $other->nanos;
    }

    public function lessThan(self $other): bool
    {
        return $this->nanos < $other->nanos;
    }

    public function toDecimal(): string
    {
        $abs = abs($this->nanos);
        $sign = $this->nanos < 0 ? '-' : '';

        return sprintf('%s%d.%09d', $sign, intdiv($abs, self::NANOS_PER_DOLLAR), $abs % self::NANOS_PER_DOLLAR);
    }

    public function jsonSerialize(): int
    {
        return $this->nanos;
    }
}
