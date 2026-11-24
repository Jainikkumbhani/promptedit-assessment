<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Generations\GenerationService;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use Illuminate\Console\Command;

/**
 * The polling safety net: webhooks are at-least-once but not guaranteed, so every
 * minute we ask Pixelforge about generations we haven't heard about in a while.
 */
class SyncActiveGenerations extends Command
{
    protected $signature = 'generations:sync {--quiet-for=30 : Only poll generations not updated for this many seconds}';

    protected $description = 'Poll Pixelforge for generations whose webhooks are late or missing';

    public function handle(GenerationService $generations): int
    {
        $quietFor = now()->subSeconds((int) $this->option('quiet-for'));

        $stale = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('pixelforge_id')
            ->where('updated_at', '<', $quietFor)
            ->pluck('id');

        $stale->each(fn (string $id) => SyncGeneration::dispatch($id));

        // A submission that never got going (e.g. the worker was down) shouldn't hold credits forever.
        Generation::query()
            ->where('status', GenerationStatus::Submitting)
            ->where('created_at', '<', now()->subMinutes(30))
            ->each(fn (Generation $generation) => $generations->failBeforeAcceptance(
                $generation, 'submission_timeout', 'We could not get Pixelforge to accept this generation in time.',
            ));

        $this->info("Queued {$stale->count()} generation(s) for a status check.");

        return self::SUCCESS;
    }
}
