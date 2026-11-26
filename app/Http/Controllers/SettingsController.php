<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateHealthSourcesRequest;
use App\Http\Requests\UpdateSegmentsSettingsRequest;
use App\Http\Requests\UpdateSportsSettingsRequest;
use App\Http\Requests\UpdateUnitsRequest;
use App\Jobs\GeocodeSegmentJob;
use App\Models\Segment;
use App\Models\SyncRun;
use App\Services\Geocoding\NominatimClient;
use App\Services\Segments\SegmentMatchingService;
use App\Services\Sync\QueueStatusService;
use App\Support\AppSettings;
use App\Support\HealthSourceRegistry;
use App\Support\SportRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(
        AppSettings $settings,
        SportRegistry $sports,
        QueueStatusService $queueStatus,
        HealthSourceRegistry $healthSources,
        NominatimClient $nominatim,
    ): Response {
        return Inertia::render('settings/Index', [
            'unit_system' => $settings->unitSystem(),
            'timezone' => $settings->displayTimezone(),
            'provider' => [
                'name' => 'Garmin Connect',
                'configured' => trim((string) config('services.garmin.key', '')) !== '',
            ],
            'backfill' => $this->backfillSummary(),
            'enrichment_queue' => fn (): array => $queueStatus->summary(),
            'geocoding' => [
                'enabled' => $nominatim->isEnabled(),
                'auto_sync' => (bool) config('services.nominatim.auto_sync', true),
                'host' => $nominatim->providerHost(),
                'is_public_endpoint' => $nominatim->isPublicHost(),
                'rate_limit_ms' => $nominatim->effectiveRateLimitMs(),
            ],
            'sports_settings' => $sports->settingsPayload(),
            'health_sources' => $healthSources->settingsPayload(),
            'segments_settings' => $this->segmentsSettingsSummary($settings),
        ]);
    }

    public function updateUnits(UpdateUnitsRequest $request, AppSettings $settings): RedirectResponse
    {
        $validated = $request->validated();
        $unitSystem = is_string($validated['unit_system'] ?? null)
            ? $validated['unit_system']
            : AppSettings::UNIT_METRIC;

        $settings->set('unit_system', $unitSystem);

        return back()->with('status', 'Unit preferences updated.');
    }

    public function updateSports(UpdateSportsSettingsRequest $request, SportRegistry $sports): RedirectResponse
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $sports->saveConfig($validated);

        return back()->with('status', 'Sports configuration updated.');
    }

    public function updateHealthSources(UpdateHealthSourcesRequest $request, HealthSourceRegistry $healthSources): RedirectResponse
    {
        /** @var array<string, mixed> $sources */
        $sources = $request->validated('sources', []);

        $healthSources->saveConfig($sources);

        return back()->with('status', 'Health data source preferences updated.');
    }

    public function updateSegments(
        UpdateSegmentsSettingsRequest $request,
        AppSettings $settings,
        SegmentMatchingService $matcher,
    ): RedirectResponse {
        $minEfforts = (int) $request->validated('min_efforts');

        $settings->set('segments_min_efforts', $minEfforts);

        if (Schema::hasTable('segments')) {
            /** @var list<string> $sports */
            $sports = Segment::query()
                ->climbs()
                ->distinct()
                ->pluck('sport_category')
                ->all();

            foreach ($sports as $sport) {
                $matcher->refreshSubClimbFlagsForSport($sport);
            }

            if (
                (bool) config('services.nominatim.enabled', true)
                && (bool) config('services.nominatim.auto_sync', true)
            ) {
                $unGeocodedIds = Segment::query()
                    ->visible($minEfforts)
                    ->whereNull('geocoded_at')
                    ->orderByDesc('effort_count')
                    ->orderBy('id')
                    ->limit(50)
                    ->pluck('id')
                    ->all();

                foreach ($unGeocodedIds as $segmentId) {
                    GeocodeSegmentJob::dispatch((int) $segmentId);
                }
            }
        }

        return back()->with(
            'status',
            "Auto-detected climb threshold updated to {$minEfforts} ".($minEfforts === 1 ? 'effort' : 'efforts').'.',
        );
    }

    /**
     * @return array{
     *     min_efforts: int,
     *     default_min_efforts: int,
     *     visible_climbs_count: int,
     *     tracked_climbs_count: int
     * }
     */
    private function segmentsSettingsSummary(AppSettings $settings): array
    {
        $minEfforts = $settings->segmentsMinEfforts();

        if (! Schema::hasTable('segments')) {
            return [
                'min_efforts' => $minEfforts,
                'default_min_efforts' => AppSettings::DEFAULT_SEGMENTS_MIN_EFFORTS,
                'visible_climbs_count' => 0,
                'tracked_climbs_count' => 0,
            ];
        }

        return [
            'min_efforts' => $minEfforts,
            'default_min_efforts' => AppSettings::DEFAULT_SEGMENTS_MIN_EFFORTS,
            'visible_climbs_count' => Segment::query()->climbs()->visible($minEfforts)->count(),
            'tracked_climbs_count' => Segment::query()->climbs()->where('is_climb_verified', true)->count(),
        ];
    }

    /**
     * @return array{
     *     last_completed_at: ?string,
     *     can_resume: bool,
     *     resume_cursor_date: ?string,
     *     resume_until_date: ?string,
     *     last_error: ?string
     * }
     */
    private function backfillSummary(): array
    {
        if (! Schema::hasTable('sync_runs')) {
            return [
                'last_completed_at' => null,
                'can_resume' => false,
                'resume_cursor_date' => null,
                'resume_until_date' => null,
                'last_error' => null,
            ];
        }

        $latestBackfill = SyncRun::query()
            ->where('type', 'backfill')
            ->orderByDesc('id')
            ->first();

        $lastCompleted = SyncRun::query()
            ->where('type', 'backfill')
            ->where('status', 'completed')
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->latest('id')
            ->first();

        $canResume = $latestBackfill !== null && $latestBackfill->isResumableBackfill();

        return [
            'last_completed_at' => $lastCompleted?->finished_at?->toIso8601String(),
            'can_resume' => $canResume,
            'resume_cursor_date' => $canResume ? $latestBackfill->cursor_date : null,
            'resume_until_date' => $canResume ? $latestBackfill->until_date : null,
            'last_error' => $latestBackfill?->status === 'failed' ? $latestBackfill->error_message : null,
        ];
    }
}
