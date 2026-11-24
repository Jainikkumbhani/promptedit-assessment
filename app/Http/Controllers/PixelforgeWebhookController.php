<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Receives Pixelforge webhooks: verify, store once per event id, answer fast,
 * and apply the event in a queued job.
 */
class PixelforgeWebhookController
{
    public function __invoke(Request $request, WebhookSignature $signature): Response|JsonResponse
    {
        // Verify against the raw body, before any JSON decoding.
        $payload = $request->getContent();

        if (! $signature->isValid($payload, $request->header('Pixelforge-Signature'))) {
            Log::warning('Rejected Pixelforge webhook with an invalid signature.');

            return response()->json(['error' => 'Invalid signature.'], 400);
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || empty($event['id']) || empty($event['type'])) {
            return response()->json(['error' => 'Malformed event.'], 400);
        }

        // The unique index on event_id makes a redelivery a no-op.
        $isNew = WebhookEvent::query()->insertOrIgnore([
            'event_id' => $event['id'],
            'type' => $event['type'],
            'pixelforge_generation_id' => $event['data']['object']['id'] ?? null,
            'delivery_attempt' => (int) $request->header('Pixelforge-Delivery-Attempt', 1),
            'payload' => $payload,
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;

        $stored = WebhookEvent::where('event_id', $event['id'])->first();

        // Dispatch for new events, and again for a duplicate we never finished
        // (e.g. the queue was down the first time). The job itself is idempotent.
        if ($isNew || ! $stored->processed_at) {
            ProcessWebhookEvent::dispatch($stored->id);
        }

        return response()->noContent();
    }
}
