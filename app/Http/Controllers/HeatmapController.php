<?php

namespace App\Http\Controllers;

use App\Jobs\SyncActivityStreamJob;
use App\Models\Activity;
use App\Services\Heatmap\HeatmapService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class HeatmapController extends Controller
{
    public function index(Request $request, HeatmapService $service): Response
    {
        $rawSport = $request->query('sport', 'all');
        $rawTimeframe = $request->query('timeframe', 'all');

        $sport = is_string($rawSport) ? $rawSport : 'all';
        $timeframe = is_string($rawTimeframe) ? $rawTimeframe : 'all';

        return Inertia::render(
            'Heatmap/Index',
            $service->tracks($sport, $timeframe),
        );
    }

    public function syncStreams(): RedirectResponse
    {
        $ids = Activity::query()
            ->where('is_indoor', false)
            ->where('is_manual', false)
            ->awaitingStreams()
            ->orderByDesc('started_at_local')
            ->pluck('id')
            ->all();

        $count = count($ids);

        if ($count === 0) {
            return back()->with('status', 'All outdoor GNSS streams are already synced.');
        }

        DB::transaction(function () use ($ids): void {
            foreach ($ids as $id) {
                SyncActivityStreamJob::dispatch((int) $id);
            }
        });

        return back()->with('status', "Requested GNSS stream sync for {$count} outdoor activity(ies).");
    }
}
