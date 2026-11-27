<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateRouteClusterRequest;
use App\Jobs\MatchUnfingerprintedRoutesJob;
use App\Models\Activity;
use App\Models\RouteCluster;
use App\Services\Routes\MatchedRouteService;
use App\Services\Routes\RouteMatchingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RouteController extends Controller
{
    public function index(Request $request, MatchedRouteService $service): Response
    {
        return Inertia::render(
            'Routes/Index',
            $service->index($request->only(['sport', 'search', 'sort'])),
        );
    }

    public function show(Request $request, RouteCluster $routeCluster, MatchedRouteService $service): Response
    {
        $rawSport = $request->query('sport');
        $sport = is_string($rawSport) && trim($rawSport) !== '' ? trim($rawSport) : null;

        return Inertia::render(
            'Routes/Show',
            $service->show($routeCluster, $sport),
        );
    }

    public function update(UpdateRouteClusterRequest $request, RouteCluster $routeCluster): RedirectResponse
    {
        $validated = $request->validated();
        $rawName = $validated['name_override'] ?? null;
        $trimmed = is_string($rawName) ? trim($rawName) : '';

        // Saving the unchanged auto-detected name must not freeze it as a custom override.
        if ($trimmed === $routeCluster->name) {
            $trimmed = '';
        }

        $routeCluster->update([
            'name_override' => $trimmed !== '' ? $trimmed : null,
        ]);

        return back()->with(
            'status',
            $trimmed !== '' ? 'Route name updated.' : 'Route name reset to auto-detected name.',
        );
    }

    public function unlinkActivity(
        RouteCluster $routeCluster,
        Activity $activity,
        RouteMatchingService $matcher,
    ): RedirectResponse {
        $clusterId = $routeCluster->id;
        $unlinked = $matcher->unlinkActivity($routeCluster, $activity);

        if (! $unlinked) {
            return back()->with('status', 'Activity was not linked to this route.');
        }

        $stillExists = RouteCluster::query()->whereKey($clusterId)->exists();

        if (! $stillExists) {
            return to_route('routes.index')->with('status', 'Activity unlinked and empty route removed.');
        }

        return back()->with('status', 'Activity unlinked from route.');
    }

    private const int SYNC_SCAN_LIMIT = 250;

    public function scan(RouteMatchingService $matcher): RedirectResponse
    {
        $pending = $matcher->unfingerprintedCount();

        if ($pending === 0) {
            return back()->with('status', 'All outdoor streamed activities are already fingerprinted.');
        }

        if ($pending > self::SYNC_SCAN_LIMIT) {
            MatchUnfingerprintedRoutesJob::dispatch(rebuild: false);

            return back()->with(
                'status',
                "Queued background route matching for {$pending} activities. Refresh shortly to see matched routes.",
            );
        }

        $result = $matcher->scanUnfingerprinted();

        if ($result['scanned'] === 0) {
            return back()->with('status', 'All outdoor streamed activities are already fingerprinted.');
        }

        return back()->with(
            'status',
            "Scanned {$result['scanned']} activity(ies); {$result['matched_routes']} matched route(s) available.",
        );
    }
}
