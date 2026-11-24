<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Credit spend, provider cost and profit per UTC day and user, reconciled against
 * Pixelforge's own GET /v1/usage. Like Pixelforge, a generation counts on the UTC
 * day it completed.
 */
class OperatorReportController
{
    private const MAX_DAYS = 92;

    public function __invoke(Request $request, PixelforgeClient $pixelforge): Response
    {
        Gate::authorize('view-operator-report');

        [$from, $to] = $this->range($request);

        $generations = Generation::query()
            ->with('user:id,name,email')
            ->where('status', GenerationStatus::Succeeded)
            ->whereBetween('completed_at', [$from->startOfDay(), $to->endOfDay()])
            ->get();

        $rows = $generations
            ->groupBy(fn (Generation $g) => $g->completed_at->utc()->toDateString().'|'.$g->user_id)
            ->map(fn (Collection $group) => [
                'date' => $group->first()->completed_at->utc()->toDateString(),
                'user' => $group->first()->user->only(['id', 'name', 'email']),
                ...$this->sums($group),
            ])
            ->sortBy([['date', 'desc'], ['user.name', 'asc']])
            ->values();

        $byDay = $generations->groupBy(fn (Generation $g) => $g->completed_at->utc()->toDateString());

        [$usage, $usageError] = $this->usage($pixelforge, $from, $to);

        $days = collect(CarbonPeriod::create($from, $to))
            ->map(fn ($day) => $day->toDateString())
            ->reverse()
            ->map(function (string $date) use ($byDay, $usage) {
                $ours = $this->sums($byDay->get($date, collect()));
                $theirs = $usage[$date] ?? null;

                return [
                    'date' => $date,
                    ...$ours,
                    'pixelforge_cost_nanos' => $theirs['cost_nanos'] ?? null,
                    'pixelforge_generations' => $theirs['generations'] ?? null,
                    'matches' => $theirs === null ? null
                        : $theirs['cost_nanos'] === $ours['provider_cost_nanos'] && $theirs['generations'] === $ours['generations'],
                ];
            })
            ->values();

        return Inertia::render('Admin/Report', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $rows,
            'days' => $days,
            'totals' => $this->sums($generations),
            'usage_error' => $usageError,
        ]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $to = CarbonImmutable::parse($request->input('to', now('UTC')->toDateString()), 'UTC');
        $from = CarbonImmutable::parse($request->input('from', $to->subDays(13)->toDateString()), 'UTC');

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            $from = $to->subDays(self::MAX_DAYS - 1);
        }

        return [$from, $to];
    }

    /** @param  Collection<int, Generation>  $generations */
    private function sums(Collection $generations): array
    {
        $charged = (int) $generations->sum('charged_nanos');
        $provider = (int) $generations->sum('provider_cost_nanos');

        return [
            'generations' => $generations->count(),
            'charged_nanos' => $charged,
            'provider_cost_nanos' => $provider,
            'profit_nanos' => $charged - $provider,
        ];
    }

    /** @return array{0: array<string, array{cost_nanos:int, generations:int}>, 1: ?string} */
    private function usage(PixelforgeClient $pixelforge, CarbonImmutable $from, CarbonImmutable $to): array
    {
        try {
            $data = $pixelforge->usage($from->toDateString(), $to->toDateString())['data'] ?? [];
        } catch (PixelforgeException $e) {
            return [[], "Couldn't load Pixelforge usage: {$e->getMessage()}"];
        }

        $byDate = [];
        foreach ($data as $day) {
            $byDate[$day['date']] = [
                'cost_nanos' => Money::fromDecimal($day['cost_usd'])->nanos,
                'generations' => (int) $day['generations'],
            ];
        }

        return [$byDate, null];
    }
}
