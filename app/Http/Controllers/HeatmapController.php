<?php

namespace App\Http\Controllers;

use App\Jobs\SyncActivityStreamJob;
use App\Models\Activity;
use App\Services\Heatmap\HeatmapService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ->whereNull('streams_synced_at')
            ->orderByDesc('started_at_local')
            ->limit(250)
            ->pluck('id')
            ->all();

        foreach ($ids as $id) {
            SyncActivityStreamJob::dispatch((int) $id);
        }

        $count = count($ids);

        if ($count === 0) {
            return back()->with('status', 'All outdoor GNSS streams are already synced.');
        }

        return back()->with('status', "Queued GNSS stream sync for {$count} outdoor activity(ies).");
    }
}
