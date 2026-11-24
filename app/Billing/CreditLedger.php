<?php

namespace App\Billing;

use App\Enums\CreditTransactionType as Type;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The only place that changes a user's balance. Every change is an append-only
 * transaction row written in the same database transaction as the balance update,
 * with the user row locked so concurrent requests can't overspend.
 *
 * Per generation, each type (hold, hold_release, charge) can be written once:
 * we check under the lock, and a unique index backs that up.
 */
class CreditLedger
{
    public function grantSignupBonus(User $user, Money $amount): void
    {
        $this->record($user, Type::SignupBonus, $amount, 'Welcome credits');
    }

    public function purchase(User $user, Money $amount): CreditTransaction
    {
        return $this->record($user, Type::Purchase, $amount, 'Bought credits (demo purchase)');
    }

    /** Reserve the worst-case cost so a user can never end up below zero. */
    public function hold(Generation $generation): void
    {
        $this->record($generation->user, Type::Hold, $generation->maxCost()->negate(), 'Reserved for generation', $generation);
    }

    /** Give the hold back without charging (the generation failed). */
    public function release(Generation $generation): void
    {
        if ($this->hasEntry($generation, Type::Hold)) {
            $this->record($generation->user, Type::HoldRelease, $generation->maxCost(), 'Released: generation failed, not charged', $generation);
        }
    }

    /** Release the hold and charge the actual cost. */
    public function settle(Generation $generation, Money $charge): void
    {
        DB::transaction(function () use ($generation, $charge) {
            $this->release($generation);
            $this->record($generation->user, Type::Charge, $charge->negate(), 'Generation cost', $generation);
        });
    }

    private function hasEntry(Generation $generation, Type $type): bool
    {
        return CreditTransaction::where('generation_id', $generation->id)->where('type', $type)->exists();
    }

    private function record(User $user, Type $type, Money $amount, string $description, ?Generation $generation = null): ?CreditTransaction
    {
        return DB::transaction(function () use ($user, $type, $amount, $description, $generation) {
            $locked = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($generation && $this->hasEntry($generation, $type)) {
                return null; // already recorded: replays are no-ops
            }

            $locked->balance_nanos += $amount->nanos;
            $locked->save();
            $user->balance_nanos = $locked->balance_nanos;

            return CreditTransaction::create([
                'user_id' => $locked->id,
                'generation_id' => $generation?->id,
                'type' => $type,
                'amount_nanos' => $amount->nanos,
                'balance_after_nanos' => $locked->balance_nanos,
                'description' => $description,
            ]);
        });
    }
}
