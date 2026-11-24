<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Generations\GenerationService;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use Illuminate\Console\Command;

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

        // Don't keep credits held forever if a submission never got going.
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
