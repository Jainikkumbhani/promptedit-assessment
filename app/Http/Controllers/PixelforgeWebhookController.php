<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class PixelforgeWebhookController
{
    public function __invoke(Request $request, WebhookSignature $signature): Response|JsonResponse
    {
        $payload = $request->getContent();

        if (! $signature->isValid($payload, $request->header('Pixelforge-Signature'))) {
            Log::warning('Rejected Pixelforge webhook with an invalid signature.');

            return response()->json(['error' => 'Invalid signature.'], 400);
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || empty($event['id']) || empty($event['type'])) {
            return response()->json(['error' => 'Malformed event.'], 400);
        }

        // unique event_id = dedupe
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

        // Re-dispatch unfinished duplicates too (e.g. queue was down the first time).
        if ($isNew || ! $stored->processed_at) {
            ProcessWebhookEvent::dispatch($stored->id);
        }

        return response()->noContent();
    }
}
