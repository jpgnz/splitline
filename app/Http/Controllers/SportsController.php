<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkReclassifyActivitiesRequest;
use App\Models\Activity;
use App\Services\Garmin\ActivityStreamSyncService;
use App\Services\Sports\EnduranceHubService;
use App\Services\Sports\FamilyOverviewService;
use App\Services\Sports\GeneralSportHubService;
use App\Support\SportRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SportsController extends Controller
{
    private const array SECONDARY_SPORTS = ['hiking', 'walking', 'swimming', 'cardio'];

    public function running(
        Request $request,
        SportRegistry $sports,
        FamilyOverviewService $overview,
        EnduranceHubService $endurance,
    ): Response {
        $config = $sports->resolvedConfig();

        if ($config['families']['running']['mode'] === 'split') {
            return Inertia::render('Sports/Overview', $overview->overview('running'));
        }

        return $this->renderEndurance('running', $request, $endurance);
    }

    public function runningDiscipline(
        string $discipline,
        Request $request,
        SportRegistry $sports,
        EnduranceHubService $endurance,
    ): Response {
        $sportKey = $this->resolveDisciplineKey($sports, 'running', $discipline);

        if ($sportKey === null) {
            abort(404);
        }

        return $this->renderEndurance($sportKey, $request, $endurance);
    }

    public function cycling(
        Request $request,
        SportRegistry $sports,
        FamilyOverviewService $overview,
        EnduranceHubService $endurance,
    ): Response {
        $config = $sports->resolvedConfig();

        if ($config['families']['cycling']['mode'] === 'split') {
            return Inertia::render('Sports/Overview', $overview->overview('cycling'));
        }

        return $this->renderEndurance('cycling', $request, $endurance);
    }

    public function cyclingDiscipline(
        string $discipline,
        Request $request,
        SportRegistry $sports,
        EnduranceHubService $endurance,
    ): Response {
        $sportKey = $this->resolveDisciplineKey($sports, 'cycling', $discipline);

        if ($sportKey === null) {
            abort(404);
        }

        return $this->renderEndurance($sportKey, $request, $endurance);
    }

    public function show(
        string $sport,
        Request $request,
        SportRegistry $sports,
        EnduranceHubService $endurance,
        GeneralSportHubService $general,
    ): Response {
        $normalized = $sports->normalizeKey($sport);

        if (! in_array($normalized, self::SECONDARY_SPORTS, true)) {
            abort(404);
        }

        $entry = $sports->get($normalized);

        if ($entry === null) {
            abort(404);
        }

        if ($entry['hub_type'] === 'endurance') {
            return $this->renderEndurance($normalized, $request, $endurance);
        }

        $rawVolumeRange = $request->query('volumeRange');
        $rawVolumeEnd = $request->query('volumeEnd');
        $rawVolumeFrom = $request->query('volumeFrom');
        $rawVolumeTo = $request->query('volumeTo');
        $rawZoneRange = $request->query('zoneRange');
        $rawZoneEnd = $request->query('zoneEnd');
        $rawZoneFrom = $request->query('zoneFrom');
        $rawZoneTo = $request->query('zoneTo');

        $volumeRange = is_string($rawVolumeRange) ? $rawVolumeRange : '12w';
        $volumeEnd = is_string($rawVolumeEnd) ? $rawVolumeEnd : null;
        $volumeFrom = is_string($rawVolumeFrom) ? $rawVolumeFrom : null;
        $volumeTo = is_string($rawVolumeTo) ? $rawVolumeTo : null;
        $zoneRange = is_string($rawZoneRange) ? $rawZoneRange : '30d';
        $zoneEnd = is_string($rawZoneEnd) ? $rawZoneEnd : null;
        $zoneFrom = is_string($rawZoneFrom) ? $rawZoneFrom : null;
        $zoneTo = is_string($rawZoneTo) ? $rawZoneTo : null;

        return Inertia::render(
            'Sports/General',
            $general->hub(
                $normalized,
                $volumeRange,
                $zoneRange,
                $volumeEnd,
                $volumeFrom,
                $volumeTo,
                $zoneEnd,
                $zoneFrom,
                $zoneTo,
            ),
        );
    }

    public function bulkReclassify(
        BulkReclassifyActivitiesRequest $request,
        ActivityStreamSyncService $streamSync,
    ): RedirectResponse {
        $validated = $request->validated();
        $rawIds = is_array($validated['activity_ids'] ?? null) ? $validated['activity_ids'] : [];
        $ids = array_values(array_unique(array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $rawIds,
        )));

        $discipline = is_string($validated['discipline'] ?? null) ? $validated['discipline'] : 'other';

        $count = Activity::query()
            ->whereIn('id', $ids)
            ->update(['discipline_override' => $discipline]);

        $activities = Activity::query()
            ->whereIn('id', $ids)
            ->with('stream')
            ->get();

        foreach ($activities as $activity) {
            $streamSync->recalculateBestEfforts($activity);
        }

        $categories = $activities->pluck('sport_category')->unique()->values();
        $singleCategory = $categories->count() === 1 ? $categories->first() : null;

        $label = $this->disciplineLabel($discipline, is_string($singleCategory) ? $singleCategory : null);
        $noun = $count === 1 ? 'activity' : 'activities';

        return back()->with('status', "Reclassified {$count} {$noun} as {$label}.");
    }

    private function renderEndurance(
        string $sportKey,
        Request $request,
        EnduranceHubService $endurance,
    ): Response {
        $rawEnvironment = $request->query('environment');
        $rawTerrain = $request->query('terrain');
        $rawVolumeRange = $request->query('volumeRange');
        $rawVolumeEnd = $request->query('volumeEnd');
        $rawVolumeFrom = $request->query('volumeFrom');
        $rawVolumeTo = $request->query('volumeTo');
        $rawYear = $request->query('year');
        $rawZoneRange = $request->query('zoneRange');
        $rawZoneEnd = $request->query('zoneEnd');
        $rawZoneFrom = $request->query('zoneFrom');
        $rawZoneTo = $request->query('zoneTo');

        $environment = is_string($rawEnvironment) ? $rawEnvironment : 'all';
        $terrain = is_string($rawTerrain) ? $rawTerrain : 'all';
        $volumeRange = is_string($rawVolumeRange) ? $rawVolumeRange : '12w';
        $volumeEnd = is_string($rawVolumeEnd) ? $rawVolumeEnd : null;
        $volumeFrom = is_string($rawVolumeFrom) ? $rawVolumeFrom : null;
        $volumeTo = is_string($rawVolumeTo) ? $rawVolumeTo : null;
        $year = is_numeric($rawYear) ? (int) $rawYear : null;
        $zoneRange = is_string($rawZoneRange) ? $rawZoneRange : '30d';
        $zoneEnd = is_string($rawZoneEnd) ? $rawZoneEnd : null;
        $zoneFrom = is_string($rawZoneFrom) ? $rawZoneFrom : null;
        $zoneTo = is_string($rawZoneTo) ? $rawZoneTo : null;

        return Inertia::render(
            'Sports/Endurance',
            $endurance->hub(
                $sportKey,
                $environment,
                $terrain,
                $volumeRange,
                $year,
                $zoneRange,
                $volumeEnd,
                $volumeFrom,
                $volumeTo,
                $zoneEnd,
                $zoneFrom,
                $zoneTo,
            ),
        );
    }

    private function resolveDisciplineKey(SportRegistry $sports, string $familyKey, string $slug): ?string
    {
        $normalizedSlug = strtolower(trim($slug));

        foreach ($sports->catalog() as $entry) {
            if ($entry['parent'] === $familyKey && $entry['slug'] === $normalizedSlug) {
                return $entry['key'];
            }
        }

        return null;
    }

    private function disciplineLabel(string $discipline, ?string $sportCategory): string
    {
        if ($sportCategory === 'running') {
            return match ($discipline) {
                'road' => 'Road Running',
                'trail' => 'Trail Running',
                'track' => 'Track Running',
                default => 'Other Running',
            };
        }

        if ($sportCategory === 'biking') {
            return match ($discipline) {
                'road' => 'Road Cycling',
                'gravel' => 'Gravel / CX',
                'mtb' => 'Mountain Biking',
                'ebike' => 'E-Bike',
                'commute' => 'Commute',
                default => 'Other Cycling',
            };
        }

        return match ($discipline) {
            'road' => 'Road',
            'trail' => 'Trail',
            'track' => 'Track',
            'gravel' => 'Gravel / CX',
            'mtb' => 'Mountain Biking',
            'ebike' => 'E-Bike',
            'commute' => 'Commute',
            default => 'Other',
        };
    }
}
