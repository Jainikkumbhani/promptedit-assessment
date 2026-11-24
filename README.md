# PromptEdit Studio

An image generation app on top of **Pixelforge**, built with Laravel 12, Vue 3 and Inertia 2. Users write a prompt, see the price before they submit, watch the image arrive live, and pay the exact Pixelforge cost plus a 25% markup. Failed generations cost nothing.

- **Live app:** _add your deployed URL_
- **Pixelforge account:** _add your Pixelforge email_

Both stretch goals are included: **credit balances** (with a demo "Buy credits") and an **operator report** that reconciles against `GET /v1/usage`.

---

## How a generation flows

```
Studio form ──POST /generations──▶ GenerationService::start()
                                    • price it from GET /v1/pricing
                                    • lock the user, hold the WORST-CASE cost
                                    • save status = submitting
                                    • dispatch SubmitGeneration (after commit)

SubmitGeneration job ──POST /v1/generations (Idempotency-Key = our id)──▶ Pixelforge
   429 / 503 → wait ≥ Retry-After, back off, give up after 8 tries → failed, hold released
   400 / 422 → failed immediately, hold released

Pixelforge ──webhook──▶ /webhooks/pixelforge
   verify HMAC + timestamp → insert by event id (unique) → 204 → ProcessWebhookEvent job

Scheduler (every minute) + "Check" button ──GET /v1/generations/{id}──▶ same code path

Every path ends in GenerationService::applyRemoteState()
   • status only moves forward; succeeded/failed are final
   • succeeded → exact cost from usage × the generation's own rates → release hold, charge once
   • failed    → release hold, charge $0
```

## Decisions (and why)

### Money
- **Integer nanodollars everywhere** (`App\Support\Money`, `BIGINT` columns). Pixelforge reports nine decimal places, so a nanodollar is exactly its smallest unit, and integers never drift the way floats do. Decimal strings from the API are parsed exactly; amounts that would need rounding are rejected, never rounded quietly.
- **Provider cost** = Σ tokens × rate, computed with the `rates` returned **on the generation**, not today's prices, because Pixelforge fixes rates when a generation is created. With its two-decimal rates every cost is a whole number of nanodollars, so we match Pixelforge to the last digit.
- **User price** = provider cost × 1.25. A 25% markup can leave a fraction of a nanodollar; we round **up**, so we never undercharge, by at most 0.75 nanodollars.
- **Estimate**: output and image tokens are exact, text tokens are `ceil(chars / 4)` ±15%, and reasoning tokens fall in a range per quality. The UI shows a typical price (mid-range reasoning) plus the full low–max range. The tests reproduce the docs' worked example ($0.373875 → $0.46734375 with markup).

### Balances (stretch goal)
- A user's balance is changed only by `CreditLedger`, which writes an append-only `credit_transactions` row in the same DB transaction, with the user row locked (`SELECT … FOR UPDATE`).
- On submit we **hold the worst case**, so concurrent generations can never push a balance below zero. When it finishes we release the hold and charge the actual cost. On failure we only release the hold.
- `(generation_id, type)` is unique, so a generation can be held, released and charged **once each**, even if two different webhook events both say "succeeded".
- New accounts get $2 of welcome credits so reviewers can try it straight away.

### Webhooks
- The route sits **outside the `web` group** (no session or CSRF). The signature is checked against the raw body with `hash_equals`, and timestamps older than 5 minutes are rejected to stop replays.
- Events are deduplicated by **event id** (unique index plus `insertOrIgnore`). We answer `204` right away and apply the event in a queued job, as the docs recommend.
- **Out of order:** `GenerationStatus::rank()` only lets status move forward, and final states never change. A late `processing` after `succeeded` is recorded and ignored.
- **Before the POST returns:** we send `metadata.generation_id`, so an early webhook still finds its generation.
- **Never delivered:** a scheduled `generations:sync` polls generations we haven't heard about for 30 seconds. Open pages nudge stale ones too, and every card has a "Check" button.

### Limits and failures
- Our generation id is the `Idempotency-Key`, so a retry after a timeout or a 503 can never create a second billable generation.
- Retries honour `Retry-After`, back off exponentially (capped at 60s, with jitter) and stop after 8 attempts. While we wait, the card says why (for example "Pixelforge is busy with 3 images").
- `content_policy_violation`, `daily_limit_exceeded` and validation errors fail immediately with a clear message. Credits are never taken.
- Input is validated against the same rules Pixelforge uses, and sizes and qualities come from `/v1/pricing`, so a typo can't hold credits for a request that will be rejected anyway.

### Operator report (stretch goal)
`/admin/report` shows credit spend, provider cost and profit **per UTC day and per user**. Like Pixelforge, a generation counts on the UTC day it **completed**. Each day is compared with `GET /v1/usage` (cost and generation count), and mismatches are flagged. Access is limited to emails in `ADMIN_EMAILS`.

### Images
Pixelforge image URLs expire after 24 hours. As the brief allows, we show the expiry state instead of copying files to our own storage.

## Testing

```bash
php artisan test        # 78 tests
```

The tests use the docs' own fixtures and cover exact pricing, the worked example, signature checks (tampered body, wrong secret, replay), duplicate and out-of-order webhooks, early webhooks, failed generations (free, hold released), 429/503 retries, the idempotency key, insufficient credits, polling, and the operator report reconciliation.

To test by hand, put these keywords in a prompt (the Studio has a "test keywords" helper): `[fail]` `[slow]` `[duplicate]` `[blocked]` `[overloaded]` `[succeed]`.

## Running locally

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
npm run build

php artisan serve                 # the app
php artisan queue:work            # submissions and webhooks
php artisan schedule:work         # polling fallback
```

Set `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` from a **local** Pixelforge key. To receive webhooks, expose the app with a tunnel (for example `cloudflared tunnel --url http://localhost:8000`) and set the key's webhook URL to `https://<tunnel>/webhooks/pixelforge`.

## Deploying (Render, free plan)

The repo ships a `Dockerfile` (FrankenPHP, PHP 8.4) and a `render.yaml` Blueprint.

1. Render dashboard: **New > Blueprint**, then pick this repository. It creates the web service and a Postgres database, and generates `APP_KEY`.
2. Fill in the three secret variables it asks for: `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` (from a **separate deployed** Pixelforge key), and `ADMIN_EMAILS`.
3. In Pixelforge, set that key's webhook URL to `https://<service>.onrender.com/webhooks/pixelforge` and press "Send test event".

`docker/start.sh` caches config, runs migrations, then starts the **queue worker** and the **scheduler** next to the web server, so a single free instance runs everything. A free instance sleeps after 15 idle minutes. Pixelforge retries webhooks that arrive during the cold start, and the polling fallback catches anything they miss.

## Where things live

| Concern | File |
|---|---|
| Money value object | `app/Support/Money.php` |
| Pricing and estimates | `app/Billing/PriceCalculator.php` |
| Balance ledger | `app/Billing/CreditLedger.php` |
| Lifecycle and state machine | `app/Generations/GenerationService.php`, `app/Enums/GenerationStatus.php` |
| Pixelforge client | `app/Services/Pixelforge/PixelforgeClient.php` |
| Webhook endpoint and signature | `app/Http/Controllers/PixelforgeWebhookController.php`, `app/Services/Pixelforge/WebhookSignature.php` |
| Jobs | `app/Jobs/SubmitGeneration.php`, `ProcessWebhookEvent.php`, `SyncGeneration.php` |
| Polling fallback | `app/Console/Commands/SyncActiveGenerations.php` |
| Operator report | `app/Http/Controllers/Admin/OperatorReportController.php` |
| UI | `resources/js/pages/{Studio,History,Credits,Admin/Report}.vue` |
