<?php

namespace App\Http\Controllers;

use App\Jobs\SyncActivityStreamJob;
use App\Models\Activity;
use App\Services\Heatmap\ExplorerTileService;
use App\Services\Heatmap\HeatmapService;
use Illuminate\Http\JsonResponse;
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
        $rawEnd = $request->query('end');
        $rawFrom = $request->query('from');
        $rawTo = $request->query('to');
        $rawOrder = $request->query('order', 'newest_on_top');

        $sport = is_string($rawSport) ? $rawSport : 'all';
        $timeframe = is_string($rawTimeframe) ? $rawTimeframe : 'all';
        $end = is_string($rawEnd) ? $rawEnd : null;
        $from = is_string($rawFrom) ? $rawFrom : null;
        $to = is_string($rawTo) ? $rawTo : null;
        $order = is_string($rawOrder) ? $rawOrder : 'newest_on_top';

        return Inertia::render(
            'Heatmap/Index',
            $service->tracks($sport, $timeframe, $end, $from, $to, $order),
        );
    }

    /**
     * Lifetime z14 Explorer tiles for all outdoor activities, independent of the heatmap filters.
     * Fetched on demand when the Explorer overlay is toggled on.
     */
    public function explorer(ExplorerTileService $explorerTiles): JsonResponse
    {
        return response()->json($explorerTiles->lifetimeSummary());
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
            return back()->with('status', 'All outdoor GPS streams are already synced.');
        }

        DB::transaction(function () use ($ids): void {
            foreach ($ids as $id) {
                SyncActivityStreamJob::dispatch((int) $id);
            }
        });

        return back()->with('status', "Requested GPS stream sync for {$count} outdoor activity(ies).");
    }
}
