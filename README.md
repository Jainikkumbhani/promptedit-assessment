# PromptEdit Studio

Take-home for the Senior Product Engineer role: an image generation app on top of Pixelforge, built with Laravel 12, Vue 3 and Inertia 2.

**Live:** https://promptedit-studio.onrender.com (free Render instance, so the first load after a while can take 30-60s)

Both stretch goals are in: credit balances, and an operator report that checks our numbers against `GET /v1/usage`.

## How it works

1. **Submit:** the form shows a live estimate. On submit I hold the worst-case cost from the user's balance, save the generation as `submitting`, and queue a job.
2. **Send to Pixelforge:** `SubmitGeneration` posts the request with our generation id as the `Idempotency-Key`. On 429/503 it waits for `Retry-After`, backs off, and gives up after 8 tries. If Pixelforge rejects it (blocked, validation, daily limit), it fails right away. Either way the hold is released.
3. **Updates:** the webhook endpoint checks the signature, stores the event by id (unique), returns 204 and processes it in a job. A scheduled `generations:sync` polls anything we haven't heard about in 30s, and the UI has a "Check" button.
4. **Billing:** everything goes through `GenerationService::applyRemoteState()`. Status only moves forward, and succeeded/failed are final. On success: cost = tokens × the generation's own rates, +25%, then the hold is released and the actual cost charged once. On failure: hold released, $0.

## Decisions worth mentioning

- **Money as integer nanodollars.** Pixelforge uses 9 decimal places, so this is exact and I never touch floats. With its 2-decimal rates the provider cost always comes out to whole nanodollars. The 25% markup can leave a fraction, which I round up, so it never undercharges (by at most 0.75 nanodollar).
- **Rates come from the generation, not `/v1/pricing`.** Pixelforge fixes rates when a generation is created.
- **Estimate:** output and image tokens are exact, text is `ceil(chars/4)` ±15%, and reasoning depends on quality. I show the middle and the full range, and hold the max. The tests check this against the worked example in the docs.
- **Ledger:** every balance change is a row in `credit_transactions`, written with the user row locked. `(generation_id, type)` is unique, so a generation can't be charged twice even if two different events both say it succeeded.
- **Webhooks:** duplicates are ignored by event id, out-of-order events are ignored by status rank, and an early webhook (before the POST returns) is matched through `metadata.generation_id`.
- **Operator report:** grouped by the UTC day a generation *completed*, the same way Pixelforge groups `/v1/usage`, so the numbers line up. Access is controlled by `ADMIN_EMAILS`.
- **Images** expire after 24h on Pixelforge. The brief said that's fine, so I don't copy them.

Things I'd add with more time: storing images on S3, websockets instead of polling the page, and a proper admin role instead of an env list.

## Tests

```bash
php artisan test
```

78 tests, using the payloads from the docs: pricing, signatures (tampered body, wrong secret, replay), duplicate and out-of-order webhooks, early webhooks, failures, 429/503 retries, insufficient credits, polling and the report.

To try things by hand, put `[fail]`, `[slow]`, `[duplicate]`, `[blocked]`, `[overloaded]` or `[succeed]` in a prompt. There's a helper for this under the Generate button.

## Running locally

```bash
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan serve
php artisan queue:work
php artisan schedule:work
```

Fill in `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` from a local Pixelforge key. To receive webhooks locally I used `cloudflared tunnel --url http://localhost:8000`.

## Deploy

`render.yaml` is a Render Blueprint (web service + Postgres, free plan). It asks for the Pixelforge key, the webhook secret and `ADMIN_EMAILS`. The free plan has no separate worker, so `docker/start.sh` runs the queue worker and scheduler in the same container as the web server.
