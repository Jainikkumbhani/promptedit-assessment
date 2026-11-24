<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property GenerationStatus $status
 */
class Generation extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'estimated_cost_nanos' => 'integer',
            'max_cost_nanos' => 'integer',
            'provider_cost_nanos' => 'integer',
            'charged_nanos' => 'integer',
            'image_url_expires_at' => 'immutable_datetime',
            'provider_created_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', GenerationStatus::active());
    }

    public function maxCost(): Money
    {
        return Money::nanos($this->max_cost_nanos);
    }

    public function imageIsAvailable(): bool
    {
        return $this->image_url !== null
            && ($this->image_url_expires_at === null || $this->image_url_expires_at->isFuture());
    }

    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'pixelforge_id' => $this->pixelforge_id,
            'status' => $this->status->value,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'estimated_cost_nanos' => $this->estimated_cost_nanos,
            'max_cost_nanos' => $this->max_cost_nanos,
            'charged_nanos' => $this->charged_nanos,
            'provider_cost_nanos' => $this->provider_cost_nanos,
            'usage' => $this->usage,
            'image_url' => $this->imageIsAvailable() ? $this->image_url : null,
            'image_expired' => $this->image_url !== null && ! $this->imageIsAvailable(),
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'retry_reason' => $this->retry_reason,
            'submit_attempts' => $this->submit_attempts,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
