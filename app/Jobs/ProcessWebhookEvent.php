<?php

namespace App\Jobs;

use App\Generations\GenerationService;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [5, 15, 60, 120];

    public function __construct(public readonly int $webhookEventId) {}

    public function handle(GenerationService $generations): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        if (! $event || $event->processed_at) {
            return;
        }

        $remote = $event->payload['data']['object'] ?? null;

        if ($event->type === 'ping' || ! is_array($remote)) {
            $this->finish($event, 'ignored');

            return;
        }

        // Webhook can arrive before our POST returns, so also match on metadata.
        $generation = Generation::query()
            ->where('pixelforge_id', $remote['id'] ?? '')
            ->when($remote['metadata']['generation_id'] ?? null, fn ($q, $id) => $q->orWhere('id', $id))
            ->first();

        if (! $generation) {
            $this->finish($event, 'unknown_generation');

            return;
        }

        $before = $generation->status;
        $after = $generations->applyRemoteState($generation, $remote)->status;

        $this->finish($event, $before === $after ? 'no_change' : "{$before->value}->{$after->value}");
    }

    private function finish(WebhookEvent $event, string $outcome): void
    {
        $event->update(['processed_at' => now(), 'outcome' => $outcome]);
    }
}
