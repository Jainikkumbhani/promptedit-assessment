<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['billing.admin_emails' => ['ops@example.com']]);
    $this->travelTo('2026-11-05 12:00:00');
});

function usageResponse(string $cost, int $generations): array
{
    return ['object' => 'usage', 'data' => [
        ['date' => '2026-11-05', 'generations' => 0, 'cost_usd' => '0.000000000'],
        ['date' => '2026-11-04', 'generations' => $generations, 'cost_usd' => $cost],
    ]];
}

it('is only for operators', function () {
    fakePixelforge();

    $this->actingAs(User::factory()->create())->get('/admin/report')->assertForbidden();
});

it('reports spend, provider cost and profit per day and user, and reconciles with Pixelforge', function () {
    fakePixelforge(['pixelforge.promptedit.dev/v1/usage*' => Http::response(usageResponse('0.305510000', 1))]);

    $customer = userWithCredits('5.00');
    $generation = acceptedGeneration($customer);
    sendWebhook(webhookEvent('generation.succeeded', succeededGeneration($generation->pixelforge_id)));

    // A failed one costs nothing and must not appear as billed.
    $failed = acceptedGeneration($customer);
    sendWebhook(webhookEvent('generation.failed', pixelforgeGeneration(['id' => $failed->pixelforge_id, 'status' => 'failed', 'error' => ['code' => 'generation_failed', 'message' => 'x']])));

    $admin = User::factory()->create(['email' => 'ops@example.com']);

    $this->actingAs($admin)->get('/admin/report?from=2026-11-04&to=2026-11-05')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Report')
            ->where('totals.generations', 1)
            ->where('totals.charged_nanos', 381_887_500)
            ->where('totals.provider_cost_nanos', 305_510_000)
            ->where('totals.profit_nanos', 76_377_500)
            ->where('rows.0.date', '2026-11-04')       // billed on the day it completed
            ->where('rows.0.user.id', $customer->id)
            ->where('days.1.date', '2026-11-04')
            ->where('days.1.matches', true)
            ->where('days.0.matches', true));
});

it('flags days that do not match Pixelforge', function () {
    fakePixelforge(['pixelforge.promptedit.dev/v1/usage*' => Http::response(usageResponse('0.999999999', 1))]);
    $generation = acceptedGeneration(userWithCredits());
    sendWebhook(webhookEvent('generation.succeeded', succeededGeneration($generation->pixelforge_id)));

    $this->actingAs(User::factory()->create(['email' => 'ops@example.com']))
        ->get('/admin/report?from=2026-11-04&to=2026-11-04')
        ->assertInertia(fn (Assert $page) => $page->where('days.0.matches', false));
});
