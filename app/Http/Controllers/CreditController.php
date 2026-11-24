<?php

namespace App\Http\Controllers;

use App\Billing\CreditLedger;
use App\Models\CreditTransaction;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CreditController
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $held = $user->generations()->active()->sum('max_cost_nanos');

        return Inertia::render('Credits', [
            'packs' => config('billing.credit_packs_usd'),
            'held_nanos' => (int) $held,
            'transactions' => $user->creditTransactions()
                ->latest('id')
                ->paginate(20)
                ->through(fn (CreditTransaction $t) => [
                    'id' => $t->id,
                    'type' => $t->type->value,
                    'description' => $t->description,
                    'amount_nanos' => $t->amount_nanos,
                    'balance_after_nanos' => $t->balance_after_nanos,
                    'generation_id' => $t->generation_id,
                    'created_at' => $t->created_at->toIso8601String(),
                ]),
        ]);
    }

    // Fake purchase, no payment.
    public function store(Request $request, CreditLedger $ledger): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', Rule::in(config('billing.credit_packs_usd'))],
        ]);

        $ledger->purchase($request->user(), Money::fromDecimal((string) $validated['amount']));

        return back()->with('success', "Added \${$validated['amount']} in credits.");
    }
}
