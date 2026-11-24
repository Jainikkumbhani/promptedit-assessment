<?php

namespace App\Billing;

use App\Enums\CreditTransactionType as Type;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * All balance changes go through here. Each one is a row in credit_transactions,
 * written with the user row locked. hold / hold_release / charge can only happen
 * once per generation (checked under the lock + unique index).
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

    public function hold(Generation $generation): void
    {
        $this->record($generation->user, Type::Hold, $generation->maxCost()->negate(), 'Reserved for generation', $generation);
    }

    public function release(Generation $generation): void
    {
        if ($this->hasEntry($generation, Type::Hold)) {
            $this->record($generation->user, Type::HoldRelease, $generation->maxCost(), 'Released: generation failed, not charged', $generation);
        }
    }

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
                return null; // already recorded
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
