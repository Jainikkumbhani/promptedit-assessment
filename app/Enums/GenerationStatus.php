<?php

namespace App\Enums;

enum GenerationStatus: string
{
    /** Saved locally; we're still getting Pixelforge to accept it (retrying limits/overloads). */
    case Submitting = 'submitting';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * How far along the lifecycle a status is. A generation only ever moves to a
     * higher rank, which is how out-of-order webhooks are made harmless.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Submitting => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    public function canMoveTo(self $next): bool
    {
        return ! $this->isFinal() && $next->rank() > $this->rank();
    }

    /** @return list<self> */
    public static function active(): array
    {
        return [self::Submitting, self::Queued, self::Processing];
    }
}
