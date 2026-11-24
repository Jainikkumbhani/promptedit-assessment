<?php

namespace App\Models;

use App\Enums\CreditTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => CreditTransactionType::class,
            'amount_nanos' => 'integer',
            'balance_after_nanos' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
