<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateHealthSourcesRequest;
use App\Http\Requests\UpdateSegmentsSettingsRequest;
use App\Http\Requests\UpdateSportsSettingsRequest;
use App\Http\Requests\UpdateUnitsRequest;
use App\Jobs\GeocodeSegmentJob;
use App\Jobs\RefreshSubClimbFlagsJob;
use App\Models\Segment;
use App\Models\SyncRun;
use App\Services\Export\DataExportService;
use App\Services\Garmin\GarminSyncService;
use App\Services\Geocoding\NominatimClient;
use App\Services\Ingestion\AutoImportService;
use App\Services\Sync\QueueStatusService;
use App\Support\AppSettings;
use App\Support\HealthSourceRegistry;
use App\Support\MapConfig;
use App\Support\SportRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Splitline\Weather\OpenMeteoConfig;

class SettingsController extends Controller
{
    public function index(
        AppSettings $settings,
        SportRegistry $sports,
        QueueStatusService $queueStatus,
        HealthSourceRegistry $healthSources,
        NominatimClient $nominatim,
        MapConfig $maps,
        AutoImportService $autoImport,
        DataExportService $dataExport,
    ): Response {
        return Inertia::render('settings/Index', [
            'unit_system' => $settings->unitSystem(),
            'timezone' => $settings->displayTimezone(),
            'provider' => [
                'name' => 'Garmin Connect',
                'configured' => GarminSyncService::isConfigured(),
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
            'weather' => $this->weatherSettingsSummary(),
            'maps_config' => $maps->settingsPayload(),
            'auto_import' => $autoImport->statusPayload(),
            'data_export' => $dataExport->summaryPayload(),
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
                RefreshSubClimbFlagsJob::dispatch($sport);
            }

            if (
                (bool) config('services.nominatim.enabled', true)
                && (bool) config('services.nominatim.auto_sync', true)
            ) {
                $unGeocodedIds = Segment::query()
                    ->awaitingGeocoding($minEfforts)
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

    /**
     * @return array{
     *     enabled: bool,
     *     host: string,
     *     is_public_endpoint: bool,
     *     is_commercial_endpoint: bool
     * }
     */
    private function weatherSettingsSummary(): array
    {
        $configuredHosts = [
            $this->resolveWeatherEndpointHost(config('services.open_meteo.forecast_url'), OpenMeteoConfig::DEFAULT_FORECAST_URL),
            $this->resolveWeatherEndpointHost(config('services.open_meteo.historical_forecast_url'), OpenMeteoConfig::DEFAULT_HISTORICAL_FORECAST_URL),
            $this->resolveWeatherEndpointHost(config('services.open_meteo.archive_url'), OpenMeteoConfig::DEFAULT_ARCHIVE_URL),
        ];

        $publicHosts = [
            $this->resolveWeatherEndpointHost(OpenMeteoConfig::DEFAULT_FORECAST_URL, OpenMeteoConfig::DEFAULT_FORECAST_URL),
            $this->resolveWeatherEndpointHost(OpenMeteoConfig::DEFAULT_HISTORICAL_FORECAST_URL, OpenMeteoConfig::DEFAULT_HISTORICAL_FORECAST_URL),
            $this->resolveWeatherEndpointHost(OpenMeteoConfig::DEFAULT_ARCHIVE_URL, OpenMeteoConfig::DEFAULT_ARCHIVE_URL),
        ];

        $allPublic = true;
        $allOpenMeteo = true;
        /** @var list<string> $selfHostedHosts */
        $selfHostedHosts = [];

        foreach ($configuredHosts as $host) {
            if (! in_array($host, $publicHosts, true)) {
                $allPublic = false;
            }

            if ($host !== 'open-meteo.com' && ! str_ends_with($host, '.open-meteo.com')) {
                $allOpenMeteo = false;
                $selfHostedHosts[] = $host;
            }
        }

        $isCommercial = ! $allPublic && $allOpenMeteo;

        if ($allPublic) {
            $displayHost = 'open-meteo.com';
        } elseif ($selfHostedHosts !== []) {
            $displayHost = implode(', ', array_values(array_unique($selfHostedHosts)));
        } else {
            $displayHost = implode(', ', array_values(array_unique($configuredHosts)));
        }

        return [
            'enabled' => (bool) config('services.open_meteo.enabled', true),
            'host' => $displayHost,
            'is_public_endpoint' => $allPublic,
            'is_commercial_endpoint' => $isCommercial,
        ];
    }

    private function resolveWeatherEndpointHost(mixed $configured, string $defaultUrl): string
    {
        $url = is_string($configured) && trim($configured) !== '' ? trim($configured) : $defaultUrl;
        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) && trim($host) !== '') {
            return strtolower(trim($host));
        }

        $fallbackHost = parse_url($defaultUrl, PHP_URL_HOST);

        return is_string($fallbackHost) && trim($fallbackHost) !== ''
            ? strtolower(trim($fallbackHost))
            : 'open-meteo.com';
    }
}
