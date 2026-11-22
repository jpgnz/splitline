<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateActivityDisciplineRequest;
use App\Models\Activity;
use App\Services\Activities\ActivityQueryService;
use App\Services\Garmin\ActivityStreamSyncService;
use App\Services\Weather\ActivityWeatherSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ActivityController extends Controller
{
    public function index(Request $request, ActivityQueryService $service): Response
    {
        return Inertia::render(
            'Activities/Index',
            $service->list($request->only([
                'search',
                'sport',
                'range',
                'from',
                'to',
                'pr',
                'favorite',
                'view',
                'month',
                'sort',
                'dir',
            ])),
        );
    }

    public function show(Activity $activity, ActivityQueryService $service): Response
    {
        return Inertia::render(
            'Activities/Show',
            $service->detail($activity),
        );
    }

    public function updateDiscipline(
        UpdateActivityDisciplineRequest $request,
        Activity $activity,
        ActivityStreamSyncService $streamSync,
    ): RedirectResponse {
        $validated = $request->validated();
        $disciplineOverride = $validated['discipline_override'] ?? null;

        $activity->update([
            'discipline_override' => is_string($disciplineOverride) ? $disciplineOverride : null,
        ]);

        $streamSync->recalculateBestEfforts($activity);

        return back()->with('status', 'Activity discipline updated.');
    }

    public function toggleFavorite(Activity $activity): RedirectResponse
    {
        $activity->update([
            'is_favorite' => ! $activity->is_favorite,
        ]);

        return back();
    }

    public function syncStreams(Activity $activity, ActivityStreamSyncService $streamSync): RedirectResponse
    {
        try {
            $streamSync->sync($activity);
        } catch (Throwable $exception) {
            return back()->with('status', 'Failed to sync activity streams: '.$exception->getMessage());
        }

        return back()->with('status', 'Activity streams synced.');
    }

    public function syncWeather(Activity $activity, ActivityWeatherSyncService $weatherSync): RedirectResponse
    {
        if (! $weatherSync->isEligible($activity)) {
            return back()->with('status', 'Weather is only available for outdoor activities with GNSS coordinates.');
        }

        try {
            $weather = $weatherSync->sync($activity, force: true);
        } catch (Throwable $exception) {
            return back()->with('status', 'Failed to sync activity weather: '.$exception->getMessage());
        }

        if ($weather === null) {
            return back()->with('status', 'No weather data available for this activity.');
        }

        return back()->with('status', 'Activity weather updated.');
    }
}
