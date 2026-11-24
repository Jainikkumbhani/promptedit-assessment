<?php

namespace App\Http\Controllers;

use App\Billing\InsufficientCredits;
use App\Billing\PriceCalculator;
use App\Enums\GenerationStatus;
use App\Generations\GenerationService;
use App\Http\Requests\GenerationRequest;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController
{
    public function __construct(
        private readonly PixelforgeClient $pixelforge,
        private readonly GenerationService $generations,
    ) {}

    public function studio(Request $request): Response
    {
        $recent = $request->user()->generations()->latest()->limit(12)->get();
        $this->pollStale($recent);

        try {
            $pricing = $this->pixelforge->pricing()->toArray();
        } catch (PixelforgeException) {
            $pricing = null;
        }

        return Inertia::render('Studio', [
            'pricing' => $pricing,
            'limits' => [
                'prompt' => GenerationRequest::MAX_PROMPT_CHARACTERS,
                'reference_images' => GenerationRequest::MAX_REFERENCE_IMAGES,
            ],
            'generations' => $recent->map->toClientArray(),
        ]);
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $page = $user->generations()->latest()->paginate(15)->withQueryString();
        $this->pollStale(collect($page->items()));

        $totals = $user->generations()
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as succeeded', [GenerationStatus::Succeeded->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as failed', [GenerationStatus::Failed->value])
            ->selectRaw('coalesce(sum(charged_nanos), 0) as spent_nanos')
            ->first();

        return Inertia::render('History', [
            'generations' => $page->through(fn (Generation $g) => $g->toClientArray()),
            'totals' => [
                'total' => (int) $totals->total,
                'succeeded' => (int) $totals->succeeded,
                'failed' => (int) $totals->failed,
                'spent_nanos' => (int) $totals->spent_nanos,
            ],
        ]);
    }

    public function store(GenerationRequest $request): RedirectResponse
    {
        try {
            $this->generations->start(
                $request->user(),
                trim((string) $request->validated('prompt')),
                (string) $request->validated('size'),
                (string) $request->validated('quality'),
                $request->referenceImages(),
            );
        } catch (InsufficientCredits $e) {
            return back()->withErrors([
                'credits' => sprintf(
                    'This could cost up to $%s and you have $%s available. Add credits to continue.',
                    rtrim(rtrim($e->required->toDecimal(), '0'), '.'),
                    rtrim(rtrim($e->available->toDecimal(), '0'), '.'),
                ),
            ]);
        }

        return back()->with('success', 'Sent to Pixelforge. Your image will appear below.');
    }

    public function estimate(GenerationRequest $request, PriceCalculator $prices): JsonResponse
    {
        $estimate = $prices->estimate(
            $this->pixelforge->pricing(),
            (string) $request->validated('prompt'),
            (string) $request->validated('size'),
            (string) $request->validated('quality'),
            count($request->referenceImages()),
        );

        return response()->json($estimate->toArray());
    }

    public function sync(Request $request, Generation $generation): RedirectResponse
    {
        abort_unless($generation->user_id === $request->user()->id, 404);

        SyncGeneration::dispatchSync($generation->id);

        return back();
    }

    /** @param  Collection<int, Generation>  $generations */
    private function pollStale(Collection $generations): void
    {
        $generations
            ->filter(fn (Generation $g) => $g->pixelforge_id
                && in_array($g->status, [GenerationStatus::Queued, GenerationStatus::Processing], true)
                && ($g->last_synced_at ?? $g->updated_at)->lt(now()->subSeconds(15)))
            ->each(fn (Generation $g) => SyncGeneration::dispatch($g->id));
    }
}
