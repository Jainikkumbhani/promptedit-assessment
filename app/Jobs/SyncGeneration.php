<?php

namespace App\Jobs;

use App\Generations\GenerationService;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

// Polling fallback for webhooks that never arrive.
class SyncGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 15;

    public function __construct(public readonly string $generationId) {}

    public function uniqueId(): string
    {
        return $this->generationId;
    }

    public function handle(PixelforgeClient $pixelforge, GenerationService $generations): void
    {
        $generation = Generation::find($this->generationId);

        if (! $generation?->pixelforge_id || $generation->status->isFinal()) {
            return;
        }

        try {
            $generations->applyRemoteState($generation, $pixelforge->getGeneration($generation->pixelforge_id));
        } catch (PixelforgeException $e) {
            Log::info('Generation sync failed.', ['generation' => $generation->id, 'code' => $e->errorCode]);
        }
    }
}
