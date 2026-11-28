<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\ActivityStream;
use App\Models\ActivityWeather;
use App\Models\DailyMetric;
use App\Models\FitnessAgeRecord;
use App\Models\GearItem;
use App\Models\Goal;
use App\Models\HeartRateZone;
use App\Models\HrvRecord;
use App\Models\RouteCluster;
use App\Models\Segment;
use App\Models\SleepRecord;
use App\Models\SyncRun;
use App\Models\Vo2MaxRecord;
use App\Models\WeightGoal;
use App\Models\WeightMeasurement;
use App\Services\Activities\ActivityDerivedMetricsService;
use App\Services\Garmin\BestEffortCalculator;
use App\Services\Gear\GearService;
use App\Services\Geocoding\ReverseGeocodingService;
use App\Services\Routes\RouteMatchingService;
use App\Services\Segments\SegmentMatchingService;
use App\Support\AppSettings;
use App\Support\SportRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Throwable;

/**
 * Single-file command that builds an isolated, privacy-clean SQLite demo database
 * populated with real OpenStreetMap routes (via BRouter with real DEM elevations),
 * real historical weather (via Open-Meteo), physics-driven FIT-grade telemetry,
 * correlated multi-year health metrics, and Splitline's own analytical pipelines.
 *
 * @phpstan-type RoutePoint array{lat: float, lng: float, ele: float, dist: float}
 * @phpstan-type RouteDefinition array{
 *     key: string,
 *     name: string,
 *     location: string,
 *     sport_category: string,
 *     sport_discipline: ?string,
 *     activity_type_key: string,
 *     brouter_profile: string,
 *     lonlats: string,
 *     unpaved_pct: ?float,
 *     jump_count: int
 * }
 * @phpstan-type CachedRoute array{
 *     points: list<RoutePoint>,
 *     distance_meters: float,
 *     elevation_gain: float,
 *     elevation_loss: float
 * }
 * @phpstan-type HourlyWeather array{
 *     temp: float,
 *     apparent: float,
 *     humidity: int,
 *     dew_point: float,
 *     precip: float,
 *     wmo: int,
 *     cloud: int,
 *     pressure: float,
 *     wind_speed: float,
 *     wind_dir: int,
 *     wind_gusts: float
 * }
 * @phpstan-type DayHealthContext array{
 *     date: string,
 *     sleep_hours: float,
 *     sleep_score: int,
 *     hrv: int,
 *     rhr: int,
 *     fitness_progress: float,
 *     readiness_factor: float
 * }
 * @phpstan-type ScheduledSession array{
 *     date: string,
 *     hour: int,
 *     minute: int,
 *     route_key: ?string,
 *     sport_category: string,
 *     sport_discipline: ?string,
 *     activity_type_key: string,
 *     is_indoor: bool,
 *     name: string,
 *     location: ?string,
 *     intensity: float,
 *     duration_minutes: ?int,
 *     hero_tag: ?string,
 *     force_windy: bool
 * }
 * @phpstan-type DemoSeriesPoint array{
 *     time: float,
 *     distance: float,
 *     altitude: ?float,
 *     speed: ?float,
 *     hr: ?int,
 *     cadence: ?int,
 *     power: ?int,
 *     grade: ?float,
 *     lat: ?float,
 *     lng: ?float,
 *     gap_speed?: float,
 *     stamina?: int,
 *     stamina_potential?: int,
 *     stride_length?: float,
 *     vertical_oscillation?: float,
 *     vertical_ratio?: float,
 *     gct?: float,
 *     respiration?: float,
 *     temperature?: float,
 *     left_balance?: float,
 *     grit?: float,
 *     flow?: float
 * }
 */
class DemoSeedCommand extends Command
{
    private const string PROVIDER = 'garmin_personal';

    private const float DEMO_CENTER_LAT = 40.0150;

    private const float DEMO_CENTER_LNG = -105.2705;

    private const string DEMO_TIMEZONE = 'America/Denver';

    private const float ATHLETE_WEIGHT_KG = 73.5;

    private const int ATHLETE_MAX_HR = 192;

    private const int ATHLETE_RESTING_HR = 46;

    /**
     * Curated Boulder, Colorado OSM route templates covering running, road cycling,
     * categorized climbs, mountain biking in 3D canyon terrain, gravel, hiking, and walking.
     *
     * @var list<RouteDefinition>
     */
    private const array ROUTE_DEFINITIONS = [
        [
            'key' => 'run_chautauqua_10k',
            'name' => 'Chautauqua & Mesa Foothills Run',
            'location' => 'Boulder',
            'sport_category' => 'running',
            'sport_discipline' => 'road',
            'activity_type_key' => 'running',
            'brouter_profile' => 'trekking',
            'lonlats' => '-105.2795,40.0005|-105.2815,39.9850|-105.2735,39.9740|-105.2610,39.9915|-105.2795,40.0005',
            'unpaved_pct' => 28.0,
            'jump_count' => 0,
        ],
        [
            'key' => 'run_creek_5k',
            'name' => 'Boulder Creek Path Tempo',
            'location' => 'Boulder',
            'sport_category' => 'running',
            'sport_discipline' => 'road',
            'activity_type_key' => 'running',
            'brouter_profile' => 'trekking',
            'lonlats' => '-105.2800,40.0145|-105.2945,40.0135|-105.2720,40.0075|-105.2635,40.0155|-105.2800,40.0145',
            'unpaved_pct' => 5.0,
            'jump_count' => 0,
        ],
        [
            'key' => 'run_wonderland_8k',
            'name' => 'Wonderland Lake Loop',
            'location' => 'Boulder',
            'sport_category' => 'running',
            'sport_discipline' => 'road',
            'activity_type_key' => 'running',
            'brouter_profile' => 'trekking',
            'lonlats' => '-105.2845,40.0495|-105.2910,40.0665|-105.2740,40.0645|-105.2750,40.0500|-105.2845,40.0495',
            'unpaved_pct' => 20.0,
            'jump_count' => 0,
        ],
        [
            'key' => 'run_reservoir_half',
            'name' => 'Boulder Reservoir Long Run',
            'location' => 'Boulder',
            'sport_category' => 'running',
            'sport_discipline' => 'road',
            'activity_type_key' => 'running',
            'brouter_profile' => 'trekking',
            'lonlats' => '-105.2640,40.0250|-105.2350,40.0580|-105.2110,40.0780|-105.2280,40.0920|-105.2520,40.0580|-105.2640,40.0250',
            'unpaved_pct' => 32.0,
            'jump_count' => 0,
        ],
        [
            'key' => 'run_sanitas_trail',
            'name' => 'Mount Sanitas Foothills Trail',
            'location' => 'Boulder',
            'sport_category' => 'running',
            'sport_discipline' => 'trail',
            'activity_type_key' => 'trail_running',
            'brouter_profile' => 'hiking-mountain',
            'lonlats' => '-105.2925,40.0205|-105.3035,40.0355|-105.2950,40.0410|-105.2885,40.0285|-105.2925,40.0205',
            'unpaved_pct' => 88.0,
            'jump_count' => 0,
        ],
        [
            'key' => 'road_flagstaff_climb',
            'name' => 'Flagstaff Mountain Climb',
            'location' => 'Boulder',
            'sport_category' => 'biking',
            'sport_discipline' => 'road',
            'activity_type_key' => 'road_biking',
            'brouter_profile' => 'fastbike',
            'lonlats' => '-105.2805,40.0145|-105.2965,40.0020|-105.3175,39.9975|-105.2965,40.0020|-105.2805,40.0145',
            'unpaved_pct' => 0.0,
            'jump_count' => 0,
        ],
        [
            'key' => 'road_sunshine_loop',
            'name' => 'Sunshine Canyon & Fourmile Loop',
            'location' => 'Boulder',
            'sport_category' => 'biking',
            'sport_discipline' => 'road',
            'activity_type_key' => 'road_biking',
            'brouter_profile' => 'fastbike',
            'lonlats' => '-105.2820,40.0175|-105.3490,40.0525|-105.3910,40.0620|-105.3510,40.0245|-105.3120,40.0095|-105.2820,40.0175',
            'unpaved_pct' => 4.0,
            'jump_count' => 0,
        ],
        [
            'key' => 'road_niwot_rolling',
            'name' => 'Niwot & Hygiene Rolling Loop',
            'location' => 'Boulder',
            'sport_category' => 'biking',
            'sport_discipline' => 'road',
            'activity_type_key' => 'road_biking',
            'brouter_profile' => 'fastbike',
            'lonlats' => '-105.2720,40.0185|-105.1660,40.1010|-105.1780,40.1860|-105.2490,40.1300|-105.2720,40.0185',
            'unpaved_pct' => 0.0,
            'jump_count' => 0,
        ],
        [
            'key' => 'road_ward_epic_century',
            'name' => 'Front Range & Peak-to-Peak Century',
            'location' => 'Boulder',
            'sport_category' => 'biking',
            'sport_discipline' => 'road',
            'activity_type_key' => 'road_biking',
            'brouter_profile' => 'fastbike',
            'lonlats' => '-105.2720,40.0185|-105.2710,40.2245|-105.4790,40.1650|-105.5085,40.0720|-105.5105,39.9615|-105.3120,40.0095|-105.1660,40.1010|-105.2720,40.0185',
            'unpaved_pct' => 0.0,
            'jump_count' => 0,
        ],
        [
            'key' => 'road_lefthand_tempo',
            'name' => 'Left Hand Canyon Tempo',
            'location' => 'Boulder',
            'sport_category' => 'biking',
            'sport_discipline' => 'road',
            'activity_type_key' => 'road_biking',
            'brouter_profile' => 'fastbike',
            'lonlats' => '-105.2820,40.0175|-105.2860,40.1040|-105.3880,40.1155|-105.2860,40.1040|-105.2820,40.0175',
            'unpaved_pct' => 0.0,
            'jump_count' => 0,
        ],
        [
            'key' => 'mtb_betasso_loop',
            'name' => 'Betasso Preserve Canyon Loop',
            'location' => 'Boulder Canyon',
            'sport_category' => 'biking',
            'sport_discipline' => 'mtb',
            'activity_type_key' => 'mountain_biking',
            'brouter_profile' => 'mtb',
            'lonlats' => '-105.3370,40.0135|-105.3490,40.0245|-105.3315,40.0225|-105.3245,40.0140|-105.3370,40.0135',
            'unpaved_pct' => 94.0,
            'jump_count' => 5,
        ],
        [
            'key' => 'mtb_marshall_mesa',
            'name' => 'Marshall Mesa & Dirty Bismark',
            'location' => 'Superior',
            'sport_category' => 'biking',
            'sport_discipline' => 'mtb',
            'activity_type_key' => 'mountain_biking',
            'brouter_profile' => 'mtb',
            'lonlats' => '-105.2305,39.9510|-105.2110,39.9320|-105.1820,39.9540|-105.2090,39.9620|-105.2305,39.9510',
            'unpaved_pct' => 86.0,
            'jump_count' => 3,
        ],
        [
            'key' => 'mtb_walker_ranch',
            'name' => 'Walker Ranch Singletrack Loop',
            'location' => 'Boulder County',
            'sport_category' => 'biking',
            'sport_discipline' => 'mtb',
            'activity_type_key' => 'mountain_biking',
            'brouter_profile' => 'mtb',
            'lonlats' => '-105.3435,39.9530|-105.3560,39.9410|-105.3740,39.9495|-105.3520,39.9615|-105.3435,39.9530',
            'unpaved_pct' => 92.0,
            'jump_count' => 4,
        ],
        [
            'key' => 'gravel_boulder_valley',
            'name' => 'Boulder Valley & Table Mountain Gravel',
            'location' => 'Niwot',
            'sport_category' => 'biking',
            'sport_discipline' => 'gravel',
            'activity_type_key' => 'gravel_cycling',
            'brouter_profile' => 'trekking',
            'lonlats' => '-105.2580,40.0480|-105.2150,40.1300|-105.1500,40.1150|-105.2100,40.0580|-105.2580,40.0480',
            'unpaved_pct' => 68.0,
            'jump_count' => 0,
        ],
        [
            'key' => 'hike_flatirons',
            'name' => 'Royal Arch & Flatirons Foothills Hike',
            'location' => 'Boulder',
            'sport_category' => 'hiking',
            'sport_discipline' => null,
            'activity_type_key' => 'hiking',
            'brouter_profile' => 'hiking-mountain',
            'lonlats' => '-105.2795,40.0005|-105.2915,39.9910|-105.2850,39.9965|-105.2795,40.0005',
            'unpaved_pct' => 96.0,
            'jump_count' => 0,
        ],
        [
            'key' => 'walk_pearl_creek',
            'name' => 'Boulder Creek & Pearl Street Walk',
            'location' => 'Boulder',
            'sport_category' => 'walking',
            'sport_discipline' => null,
            'activity_type_key' => 'walking',
            'brouter_profile' => 'trekking',
            'lonlats' => '-105.2790,40.0170|-105.2650,40.0155|-105.2700,40.0085|-105.2790,40.0170',
            'unpaved_pct' => 10.0,
            'jump_count' => 0,
        ],
    ];

    /**
     * @var list<int>
     */
    private const array POWER_CURVE_DURATIONS = [1, 2, 5, 10, 20, 30, 60, 120, 300, 600, 1200, 1800, 3600, 7200];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'demo:seed
        {--database=database/demo.sqlite : Target SQLite path (defaults to database/demo.sqlite; never touches database/database.sqlite unless explicitly passed with --force)}
        {--force : Overwrite an existing target SQLite database}
        {--fresh-routes : Ignore cached BRouter route geometries and re-fetch from brouter.de}
        {--skip-weather : Synthesize Colorado seasonal weather locally instead of fetching Open-Meteo archive data}
        {--skip-geocode : Skip live Nominatim reverse geocoding for matched routes and climbs}
        {--days=730 : Trailing calendar days of history to generate (minimum 90)}
        {--end-date= : Final calendar date YYYY-MM-DD (defaults to today)}
        {--seed=424242 : Deterministic PRNG seed}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Build an isolated SQLite demo database populated with real OSM routes, DEM elevations, historical weather, physics-driven FIT telemetry, and correlated health history';

    private Randomizer $rng;

    /**
     * Execute the console command.
     */
    public function handle(
        BestEffortCalculator $bestEffortCalculator,
        ActivityDerivedMetricsService $derivedMetricsService,
        GearService $gearService,
        RouteMatchingService $routeMatchingService,
        SegmentMatchingService $segmentMatchingService,
        ReverseGeocodingService $geocodingService,
        AppSettings $appSettings,
        SportRegistry $sportRegistry,
    ): int {
        $startedAt = microtime(true);

        $seed = is_numeric($this->option('seed')) ? (int) $this->option('seed') : 424242;
        $this->rng = new Randomizer(new Mt19937($seed));

        $days = is_numeric($this->option('days')) ? (int) $this->option('days') : 730;

        if ($days < 90 || $days > 1825) {
            $this->components->error('The --days option must be between 90 and 1825.');

            return self::FAILURE;
        }

        $rawEndDate = $this->option('end-date');

        try {
            $endDate = is_string($rawEndDate) && trim($rawEndDate) !== ''
                ? CarbonImmutable::createFromFormat('Y-m-d', trim($rawEndDate), self::DEMO_TIMEZONE)->startOfDay()
                : CarbonImmutable::now(self::DEMO_TIMEZONE)->startOfDay();
        } catch (Throwable) {
            $this->components->error('The --end-date option must be a valid YYYY-MM-DD date.');

            return self::FAILURE;
        }

        $startDate = $endDate->subDays($days - 1);

        $dbPath = $this->resolveTargetDatabasePath((string) $this->option('database'));

        if (! $this->prepareTargetDatabase($dbPath, (bool) $this->option('force'), $appSettings, $sportRegistry)) {
            return self::FAILURE;
        }

        // Keep background enrichments off while seeding so every pipeline stage runs deterministically inline.
        config([
            'services.garmin.auto_sync_streams' => false,
            'services.open_meteo.auto_sync' => false,
            'services.nominatim.auto_sync' => false,
        ]);

        $this->components->info("Seeding demo database [{$dbPath}] from {$startDate->toDateString()} to {$endDate->toDateString()} ({$days} days)...");

        try {
            $routes = $this->loadRoutes((bool) $this->option('fresh-routes'));
        } catch (Throwable $exception) {
            $this->components->error("Failed to fetch real OSM routes: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $hourlyWeather = $this->loadHistoricalWeather(
            $startDate,
            $endDate,
            (bool) $this->option('skip-weather'),
        );

        $this->seedProfileSettingsAndMetadata($appSettings, $startDate, $endDate);

        $this->components->info('Generating correlated training schedule & 2-year health telemetry...');
        $schedule = $this->buildTrainingSchedule($startDate, $endDate);
        $healthByDate = $this->seedHealthHistory($startDate, $endDate, $schedule);

        $this->components->info('Simulating physics-driven FIT telemetry streams for '.count($schedule).' activities...');

        /** @var array<string, Activity> $heroActivities */
        $heroActivities = [];
        $createdActivities = 0;

        DB::transaction(function () use (
            $schedule,
            $routes,
            $hourlyWeather,
            $healthByDate,
            $bestEffortCalculator,
            $derivedMetricsService,
            $gearService,
            &$heroActivities,
            &$createdActivities,
        ): void {
            foreach ($schedule as $index => $session) {
                $health = $healthByDate[$session['date']];
                $activity = $this->createSimulatedActivity(
                    session: $session,
                    sequenceIndex: $index + 1,
                    routes: $routes,
                    hourlyWeather: $hourlyWeather,
                    health: $health,
                    bestEffortCalculator: $bestEffortCalculator,
                    derivedMetricsService: $derivedMetricsService,
                    gearService: $gearService,
                );

                if ($session['hero_tag'] !== null) {
                    $heroActivities[$session['hero_tag']] = $activity;
                }

                $createdActivities++;
            }
        });

        $this->components->info("Created {$createdActivities} activities with full streams, splits, best efforts, weather, and derived metrics.");

        $this->components->info('Running Splitline route matching (routes:match)...');
        $routeSummary = $routeMatchingService->rebuildAll(chunkSize: 100);
        $this->components->twoColumnDetail('Matched Routes (2+ attempts)', (string) $routeSummary['matched_routes']);

        $this->components->info('Running Splitline climb detection & segment matching (segments:scan)...');
        $segmentSummary = $segmentMatchingService->rebuildAll(chunkSize: 50);
        $this->components->twoColumnDetail('Detected Climbs', (string) $segmentSummary['climbs_created']);
        $this->components->twoColumnDetail('Segment Efforts', (string) $segmentSummary['efforts_recorded']);

        if (! (bool) $this->option('skip-geocode')) {
            $this->geocodeMatchedRoutesAndSegments($geocodingService);
        } else {
            $this->applyOfflinePlaceLabels();
        }

        // Clear any deferred enrichment jobs queued by route/segment services so the demo sidebar shows an idle synced state.
        DB::table('jobs')->delete();

        $elapsed = round(microtime(true) - $startedAt, 1);

        $this->newLine();
        $this->components->info("Demo database ready in {$elapsed}s!");
        $this->renderSummaryAndUrls($dbPath, $heroActivities);

        return self::SUCCESS;
    }

    private function resolveTargetDatabasePath(string $rawPath): string
    {
        $trimmed = trim($rawPath);

        if ($trimmed === '') {
            return database_path('demo.sqlite');
        }

        if (str_starts_with($trimmed, '/')) {
            return $trimmed;
        }

        return base_path($trimmed);
    }

    private function prepareTargetDatabase(
        string $dbPath,
        bool $force,
        AppSettings $appSettings,
        SportRegistry $sportRegistry,
    ): bool {
        $realDbPath = realpath(database_path('database.sqlite')) ?: database_path('database.sqlite');
        $normalizedTarget = realpath($dbPath) ?: $dbPath;

        if ($normalizedTarget === $realDbPath && ! $force) {
            $this->components->error('Refusing to overwrite database/database.sqlite. Use --database=database/demo.sqlite (default) or pass --force explicitly.');

            return false;
        }

        if (file_exists($dbPath) && ! $force) {
            $this->components->error("Target database already exists: {$dbPath}. Pass --force to rebuild it from scratch.");

            return false;
        }

        $directory = dirname($dbPath);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->components->error("Unable to create directory: {$directory}");

            return false;
        }

        foreach ([$dbPath, "{$dbPath}-wal", "{$dbPath}-shm"] as $file) {
            if (file_exists($file) && ! @unlink($file)) {
                $this->components->error("Unable to remove existing file: {$file}");

                return false;
            }
        }

        if (@touch($dbPath) === false) {
            $this->components->error("Unable to create SQLite file: {$dbPath}");

            return false;
        }

        $baseSqliteConfig = config('database.connections.sqlite', []);
        $demoConnectionConfig = array_merge(
            is_array($baseSqliteConfig) ? $baseSqliteConfig : [],
            [
                'driver' => 'sqlite',
                'url' => null,
                'database' => $dbPath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        );

        config([
            'database.connections.demo_seed' => $demoConnectionConfig,
            'database.default' => 'demo_seed',
        ]);

        DB::purge('demo_seed');
        DB::setDefaultConnection('demo_seed');

        $this->components->info('Running database migrations on demo SQLite file...');
        $exitCode = Artisan::call('migrate', [
            '--database' => 'demo_seed',
            '--force' => true,
        ]);

        if ($exitCode !== 0) {
            $this->components->error('Database migrations failed: '.trim(Artisan::output()));

            return false;
        }

        $appSettings->flushMemo();
        $sportRegistry->flushMemo();

        return true;
    }

    /**
     * Load real OSM road & trail geometries with DEM elevation from BRouter, cached locally.
     *
     * @return array<string, CachedRoute>
     */
    private function loadRoutes(bool $fresh): array
    {
        $cachePath = storage_path('app/demo-routes-cache.json');

        /** @var array<string, CachedRoute> $cached */
        $cached = [];

        if (! $fresh && is_file($cachePath)) {
            $decoded = json_decode((string) file_get_contents($cachePath), true);

            if (is_array($decoded)) {
                /** @var array<string, CachedRoute> $decoded */
                $cached = $decoded;
            }
        }

        $updated = false;

        foreach (self::ROUTE_DEFINITIONS as $definition) {
            $key = $definition['key'];

            if (isset($cached[$key]) && count($cached[$key]['points']) >= 20) {
                continue;
            }

            $this->components->twoColumnDetail("Fetching OSM route [{$key}]", $definition['brouter_profile']);

            $route = $this->fetchBrouterRoute($definition['lonlats'], $definition['brouter_profile']);
            $cached[$key] = $route;
            $updated = true;

            usleep(250_000);
        }

        if ($updated) {
            $cacheDir = dirname($cachePath);

            if (! is_dir($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }

            file_put_contents($cachePath, json_encode($cached, JSON_PRETTY_PRINT));
        }

        return $cached;
    }

    /**
     * Fetch a real road/trail GeoJSON track with DEM elevation from BRouter and lightly smooth altitude.
     *
     * @return CachedRoute
     */
    private function fetchBrouterRoute(string $lonlats, string $profile): array
    {
        $url = 'https://brouter.de/brouter?lonlats='.urlencode($lonlats)
            .'&profile='.urlencode($profile)
            .'&alternativeidx=0&format=geojson';

        $response = Http::timeout(30)
            ->retry(2, 500)
            ->withHeaders(['User-Agent' => 'SplitlineDemoSeeder/1.0'])
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("BRouter returned HTTP {$response->status()} for profile {$profile}.");
        }

        $payload = $response->json();

        if (! is_array($payload) || ! isset($payload['features'][0]['geometry']['coordinates']) || ! is_array($payload['features'][0]['geometry']['coordinates'])) {
            throw new RuntimeException("Invalid GeoJSON payload from BRouter for profile {$profile}.");
        }

        /** @var list<array{0: float, 1: float, 2?: float}> $rawCoords */
        $rawCoords = $payload['features'][0]['geometry']['coordinates'];

        if (count($rawCoords) < 10) {
            throw new RuntimeException("BRouter returned too few coordinates for profile {$profile}.");
        }

        /** @var list<array{lat: float, lng: float, ele: float}> $clean */
        $clean = [];
        $lastEle = 1650.0;

        foreach ($rawCoords as $coord) {
            $lng = (float) $coord[0];
            $lat = (float) $coord[1];
            $ele = isset($coord[2]) ? (float) $coord[2] : $lastEle;
            $lastEle = $ele;

            $clean[] = [
                'lat' => $lat,
                'lng' => $lng,
                'ele' => $ele,
            ];
        }

        // Light 5-point moving average on SRTM altitude to remove single-node spikes while preserving real climb grades.
        $count = count($clean);
        /** @var list<RoutePoint> $points */
        $points = [];
        $cumDist = 0.0;
        $gain = 0.0;
        $loss = 0.0;
        $prevSmoothEle = null;

        for ($i = 0; $i < $count; $i++) {
            $lo = max(0, $i - 2);
            $hi = min($count - 1, $i + 2);
            $sumEle = 0.0;

            for ($k = $lo; $k <= $hi; $k++) {
                $sumEle += $clean[$k]['ele'];
            }

            $smoothEle = round($sumEle / ($hi - $lo + 1), 1);

            if ($i > 0) {
                $cumDist += RouteMatchingService::haversineMeters(
                    $clean[$i - 1]['lat'],
                    $clean[$i - 1]['lng'],
                    $clean[$i]['lat'],
                    $clean[$i]['lng'],
                );
            }

            if ($prevSmoothEle !== null) {
                $dEle = $smoothEle - $prevSmoothEle;

                if ($dEle > 0.3) {
                    $gain += $dEle;
                } elseif ($dEle < -0.3) {
                    $loss += abs($dEle);
                }
            }

            $prevSmoothEle = $smoothEle;

            $points[] = [
                'lat' => round($clean[$i]['lat'], 6),
                'lng' => round($clean[$i]['lng'], 6),
                'ele' => $smoothEle,
                'dist' => round($cumDist, 2),
            ];
        }

        return [
            'points' => $points,
            'distance_meters' => round($cumDist, 1),
            'elevation_gain' => round($gain, 1),
            'elevation_loss' => round($loss, 1),
        ];
    }

    /**
     * Load real historical hourly Boulder weather from Open-Meteo Archive API (cached locally),
     * falling back to realistic Colorado seasonal weather if skipped or offline.
     *
     * @return array<string, HourlyWeather>
     */
    private function loadHistoricalWeather(
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        bool $skipWeather,
    ): array {
        if ($skipWeather) {
            return [];
        }

        $cacheKey = $startDate->toDateString().'_'.$endDate->toDateString();
        $cachePath = storage_path('app/demo-weather-cache.json');

        if (is_file($cachePath)) {
            $decoded = json_decode((string) file_get_contents($cachePath), true);

            if (is_array($decoded) && ($decoded['key'] ?? null) === $cacheKey && is_array($decoded['hours'] ?? null)) {
                /** @var array<string, HourlyWeather> $hours */
                $hours = $decoded['hours'];
                $this->components->twoColumnDetail('Weather Archive', 'loaded '.count($hours).' cached hourly observations');

                return $hours;
            }
        }

        $this->components->info('Fetching real historical hourly Boulder weather from Open-Meteo Archive...');

        /** @var array<string, HourlyWeather> $hours */
        $hours = [];

        // Open-Meteo archive lags ~5 days behind real time; fetch up to 6 days before real-world today.
        $archiveCap = CarbonImmutable::now('UTC')->subDays(6)->startOfDay();
        $effectiveEnd = $endDate->lessThan($archiveCap) ? $endDate : $archiveCap;

        if ($effectiveEnd->greaterThanOrEqualTo($startDate)) {
            $cursor = $startDate;

            while ($cursor->lessThanOrEqualTo($effectiveEnd)) {
                $chunkEnd = $cursor->addDays(364);

                if ($chunkEnd->greaterThan($effectiveEnd)) {
                    $chunkEnd = $effectiveEnd;
                }

                try {
                    $response = Http::timeout(25)
                        ->retry(2, 500)
                        ->get('https://archive-api.open-meteo.com/v1/archive', [
                            'latitude' => self::DEMO_CENTER_LAT,
                            'longitude' => self::DEMO_CENTER_LNG,
                            'start_date' => $cursor->toDateString(),
                            'end_date' => $chunkEnd->toDateString(),
                            'hourly' => implode(',', [
                                'temperature_2m',
                                'apparent_temperature',
                                'relative_humidity_2m',
                                'dew_point_2m',
                                'precipitation',
                                'weather_code',
                                'cloud_cover',
                                'surface_pressure',
                                'wind_speed_10m',
                                'wind_direction_10m',
                                'wind_gusts_10m',
                            ]),
                            'wind_speed_unit' => 'kmh',
                            'timezone' => self::DEMO_TIMEZONE,
                        ]);

                    if ($response->successful()) {
                        $hourly = $response->json('hourly');

                        if (is_array($hourly) && is_array($hourly['time'] ?? null)) {
                            $times = $hourly['time'];
                            $count = count($times);

                            for ($i = 0; $i < $count; $i++) {
                                $t = (string) $times[$i];
                                $temp = is_numeric($hourly['temperature_2m'][$i] ?? null) ? (float) $hourly['temperature_2m'][$i] : 14.0;
                                $hours[$t] = [
                                    'temp' => round($temp, 1),
                                    'apparent' => round((float) ($hourly['apparent_temperature'][$i] ?? $temp), 1),
                                    'humidity' => (int) round((float) ($hourly['relative_humidity_2m'][$i] ?? 45)),
                                    'dew_point' => round((float) ($hourly['dew_point_2m'][$i] ?? ($temp - 8.0)), 1),
                                    'precip' => round((float) ($hourly['precipitation'][$i] ?? 0.0), 2),
                                    'wmo' => (int) ($hourly['weather_code'][$i] ?? 1),
                                    'cloud' => (int) round((float) ($hourly['cloud_cover'][$i] ?? 25)),
                                    'pressure' => round((float) ($hourly['surface_pressure'][$i] ?? 836.0), 1),
                                    'wind_speed' => round((float) ($hourly['wind_speed_10m'][$i] ?? 12.0), 1),
                                    'wind_dir' => ((int) round((float) ($hourly['wind_direction_10m'][$i] ?? 280))) % 360,
                                    'wind_gusts' => round((float) ($hourly['wind_gusts_10m'][$i] ?? 22.0), 1),
                                ];
                            }
                        }
                    }
                } catch (Throwable $exception) {
                    $this->components->warn("Open-Meteo archive chunk skipped ({$exception->getMessage()}); using seasonal fallback for missing hours.");
                }

                $cursor = $chunkEnd->addDay();
            }
        }

        if ($hours !== []) {
            file_put_contents($cachePath, json_encode([
                'key' => $cacheKey,
                'hours' => $hours,
            ]));
            $this->components->twoColumnDetail('Weather Archive', 'fetched '.count($hours).' hourly observations');
        } else {
            $this->components->warn('Open-Meteo archive unavailable; synthesizing realistic Colorado seasonal weather.');
        }

        return $hours;
    }

    private function seedProfileSettingsAndMetadata(
        AppSettings $appSettings,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): void {
        $appSettings->setMany([
            'unit_system' => AppSettings::UNIT_METRIC,
            'segments_min_efforts' => 5,
        ]);

        foreach ([
            ['sport' => 'DEFAULT', 'lthr' => 168, 'zones' => [96, 115, 134, 154, 173]],
            ['sport' => 'RUNNING', 'lthr' => 174, 'zones' => [124, 138, 152, 166, 179]],
            ['sport' => 'CYCLING', 'lthr' => 169, 'zones' => [120, 135, 149, 163, 176]],
        ] as $zoneConfig) {
            HeartRateZone::query()->create([
                'provider' => self::PROVIDER,
                'sport' => $zoneConfig['sport'],
                'training_method' => 'HR_MAX',
                'max_hr' => self::ATHLETE_MAX_HR,
                'resting_hr' => self::ATHLETE_RESTING_HR,
                'lactate_threshold_hr' => $zoneConfig['lthr'],
                'zone1_floor' => $zoneConfig['zones'][0],
                'zone2_floor' => $zoneConfig['zones'][1],
                'zone3_floor' => $zoneConfig['zones'][2],
                'zone4_floor' => $zoneConfig['zones'][3],
                'zone5_floor' => $zoneConfig['zones'][4],
            ]);
        }

        $purchasedStart = $startDate->subMonths(4)->toDateString();

        GearItem::query()->create([
            'name' => 'Canyon Ultimate CF SLX',
            'brand' => 'Canyon',
            'model' => 'Ultimate CF SLX 8',
            'type' => 'road_bike',
            'default_sport' => 'road_cycling',
            'initial_distance_meters' => 1_850_000.0,
            'max_distance_meters' => 18_000_000.0,
            'purchased_at' => $purchasedStart,
            'notes' => 'Primary Front Range road & climbing bike with dual-sided power meter.',
        ]);

        GearItem::query()->create([
            'name' => 'Santa Cruz Tallboy 5',
            'brand' => 'Santa Cruz',
            'model' => 'Tallboy C GX AXS',
            'type' => 'mtb_bike',
            'default_sport' => 'mtb_cycling',
            'initial_distance_meters' => 420_000.0,
            'max_distance_meters' => 6_000_000.0,
            'purchased_at' => $purchasedStart,
            'notes' => 'Downcountry trail setup for Betasso, Walker Ranch, and Marshall Mesa.',
        ]);

        GearItem::query()->create([
            'name' => 'Specialized Diverge STR',
            'brand' => 'Specialized',
            'model' => 'Diverge Pro',
            'type' => 'gravel_bike',
            'default_sport' => 'gravel_cycling',
            'initial_distance_meters' => 310_000.0,
            'max_distance_meters' => 10_000_000.0,
            'purchased_at' => $purchasedStart,
            'notes' => '42mm tubeless setup for Boulder County dirt roads.',
        ]);

        GearItem::query()->create([
            'name' => 'Saucony Endorphin Speed 4',
            'brand' => 'Saucony',
            'model' => 'Endorphin Speed 4',
            'type' => 'running_shoe',
            'default_sport' => 'road_running',
            'initial_distance_meters' => 85_000.0,
            'max_distance_meters' => 750_000.0,
            'purchased_at' => $endDate->subMonths(7)->toDateString(),
            'notes' => 'Daily road & tempo trainer.',
        ]);

        GearItem::query()->create([
            'name' => 'Hoka Tecton X 3',
            'brand' => 'Hoka',
            'model' => 'Tecton X 3',
            'type' => 'trail_shoe',
            'default_sport' => 'trail_running',
            'initial_distance_meters' => 40_000.0,
            'max_distance_meters' => 650_000.0,
            'purchased_at' => $endDate->subMonths(9)->toDateString(),
            'notes' => 'Foothills singletrack & Sanitas climbs.',
        ]);

        $currentYear = (int) $endDate->year;

        Goal::query()->create([
            'title' => 'Weekly Running Distance',
            'sport' => 'running',
            'metric' => 'distance_km',
            'period' => 'weekly',
            'target_value' => 30.0,
            'year' => null,
            'is_active' => true,
        ]);

        Goal::query()->create([
            'title' => 'Weekly Cycling Volume',
            'sport' => 'cycling',
            'metric' => 'distance_km',
            'period' => 'weekly',
            'target_value' => 110.0,
            'year' => null,
            'is_active' => true,
        ]);

        Goal::query()->create([
            'title' => "{$currentYear} Climbing Target",
            'sport' => 'all',
            'metric' => 'elevation_m',
            'period' => 'yearly',
            'target_value' => 65000.0,
            'year' => $currentYear,
            'is_active' => true,
        ]);

        Goal::query()->create([
            'title' => 'Weekly Active Hours',
            'sport' => 'all',
            'metric' => 'duration_hours',
            'period' => 'weekly',
            'target_value' => 8.5,
            'year' => null,
            'is_active' => true,
        ]);

        WeightGoal::query()->create([
            'provider' => self::PROVIDER,
            'start_date' => $startDate->toDateString(),
            'end_date' => null,
            'goal_grams' => 72000,
            'source_category' => 'USER_SET',
            'garmin_updated_at' => $endDate->subMonths(2)->utc(),
        ]);

        SyncRun::query()->create([
            'provider' => self::PROVIDER,
            'type' => 'backfill',
            'status' => 'completed',
            'started_at' => $endDate->setTime(6, 10)->utc(),
            'finished_at' => $endDate->setTime(6, 14)->utc(),
            'cursor_date' => $endDate->toDateString(),
            'until_date' => $startDate->toDateString(),
            'items_synced' => 1200,
            'initial_setup_completed' => true,
        ]);
    }

    /**
     * Build a realistic 2-year multi-sport training calendar with periodic deload weeks,
     * progressive Year-2 volume growth, and pinned showcase hero activities.
     *
     * @return list<ScheduledSession>
     */
    private function buildTrainingSchedule(CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        /** @var array<string, list<ScheduledSession>> $byDate */
        $byDate = [];
        $totalDays = (int) $startDate->diffInDays($endDate) + 1;

        // Pin showcase hero dates near the end of the timeline so recent dashboards, calendars, and hero views shine.
        $heroRunDate = $endDate->subDays(6)->toDateString();
        $heroMtbDate = $endDate->subDays(13)->toDateString();
        $heroCenturyDate = $endDate->subDays(24)->toDateString();
        $heroFlagstaffDate = $endDate->subDays(2)->toDateString();

        $pinnedDates = [
            $heroRunDate => true,
            $heroMtbDate => true,
            $heroCenturyDate => true,
            $heroFlagstaffDate => true,
        ];

        $byDate[$heroRunDate][] = [
            'date' => $heroRunDate,
            'hour' => 7,
            'minute' => 15,
            'route_key' => 'run_chautauqua_10k',
            'sport_category' => 'running',
            'sport_discipline' => 'road',
            'activity_type_key' => 'running',
            'is_indoor' => false,
            'name' => 'Chautauqua & Mesa Foothills Run',
            'location' => 'Boulder',
            'intensity' => 0.86,
            'duration_minutes' => null,
            'hero_tag' => 'hero_run',
            'force_windy' => false,
        ];

        $byDate[$heroMtbDate][] = [
            'date' => $heroMtbDate,
            'hour' => 9,
            'minute' => 30,
            'route_key' => 'mtb_betasso_loop',
            'sport_category' => 'biking',
            'sport_discipline' => 'mtb',
            'activity_type_key' => 'mountain_biking',
            'is_indoor' => false,
            'name' => 'Betasso Preserve Canyon Loop',
            'location' => 'Boulder Canyon',
            'intensity' => 0.88,
            'duration_minutes' => null,
            'hero_tag' => 'hero_mtb',
            'force_windy' => false,
        ];

        $byDate[$heroCenturyDate][] = [
            'date' => $heroCenturyDate,
            'hour' => 6,
            'minute' => 45,
            'route_key' => 'road_ward_epic_century',
            'sport_category' => 'biking',
            'sport_discipline' => 'road',
            'activity_type_key' => 'road_biking',
            'is_indoor' => false,
            'name' => 'Front Range & Peak-to-Peak Century',
            'location' => 'Boulder',
            'intensity' => 0.78,
            'duration_minutes' => null,
            'hero_tag' => 'hero_road',
            'force_windy' => true,
        ];

        $byDate[$heroFlagstaffDate][] = [
            'date' => $heroFlagstaffDate,
            'hour' => 7,
            'minute' => 10,
            'route_key' => 'road_flagstaff_climb',
            'sport_category' => 'biking',
            'sport_discipline' => 'road',
            'activity_type_key' => 'road_biking',
            'is_indoor' => false,
            'name' => 'Flagstaff Mountain Climb',
            'location' => 'Boulder',
            'intensity' => 0.94,
            'duration_minutes' => null,
            'hero_tag' => 'hero_climb',
            'force_windy' => false,
        ];

        for ($dayOffset = 0; $dayOffset < $totalDays; $dayOffset++) {
            $date = $startDate->addDays($dayOffset);
            $dateStr = $date->toDateString();

            if (isset($pinnedDates[$dateStr])) {
                continue;
            }

            $dow = (int) $date->dayOfWeekIso; // 1 = Mon .. 7 = Sun
            $weekIdx = intdiv($dayOffset, 7);
            $progress = $dayOffset / max(1, $totalDays - 1);
            $isDeloadWeek = ($weekIdx % 4) === 3 && $dayOffset < ($totalDays - 21);
            $month = (int) $date->month;
            $isWinter = in_array($month, [12, 1, 2], true);

            // Slightly higher consistency in Year 2 so Year-in-Sport YoY metrics look rewarding.
            $skipChance = ($isDeloadWeek ? 0.36 : 0.16) - ($progress * 0.05);

            // Keep the final 30 days nicely populated for Dashboard & Calendar screenshots.
            if ($dayOffset >= $totalDays - 30) {
                $skipChance = 0.08;
            }

            if ($this->randFloat(0.0, 1.0) < $skipChance) {
                continue;
            }

            // Pick time of day across morning / midday / evening so Insights Time-of-Day & Evening Recovery matrix populate richly.
            $timeRoll = $this->randFloat(0.0, 1.0);
            $hour = match (true) {
                $dow >= 6 => $this->rng->getInt(7, 10),
                $timeRoll < 0.45 => $this->rng->getInt(6, 8),
                $timeRoll < 0.68 => $this->rng->getInt(12, 14),
                default => $this->rng->getInt(17, 19),
            };
            $minute = $this->rng->getInt(0, 55);

            $intensity = round($this->randFloat(0.68, 0.94), 2);
            $byDate[$dateStr][] = $this->pickSessionForDay($dateStr, $dow, $weekIdx, $isWinter, $isDeloadWeek, $hour, $minute, $intensity);
        }

        ksort($byDate);

        /** @var list<ScheduledSession> $flat */
        $flat = [];

        foreach ($byDate as $daySessions) {
            usort($daySessions, static fn (array $a, array $b): int => ($a['hour'] * 60 + $a['minute']) <=> ($b['hour'] * 60 + $b['minute']));

            foreach ($daySessions as $item) {
                $flat[] = $item;
            }
        }

        return $flat;
    }

    /**
     * @return ScheduledSession
     */
    private function pickSessionForDay(
        string $dateStr,
        int $dow,
        int $weekIdx,
        bool $isWinter,
        bool $isDeloadWeek,
        int $hour,
        int $minute,
        float $intensity,
    ): array {
        $roll = $this->randFloat(0.0, 1.0);

        if ($dow === 1) {
            // Monday: recovery walk or strength/cardio
            if ($roll < 0.55) {
                return $this->routeSession($dateStr, $hour, $minute, 'walk_pearl_creek', 0.52);
            }

            return [
                'date' => $dateStr,
                'hour' => $hour,
                'minute' => $minute,
                'route_key' => null,
                'sport_category' => 'cardio',
                'sport_discipline' => null,
                'activity_type_key' => 'strength_training',
                'is_indoor' => true,
                'name' => $roll < 0.78 ? 'Core & Mobility Strength' : 'Kettlebell & Conditioning',
                'location' => null,
                'intensity' => 0.68,
                'duration_minutes' => $this->rng->getInt(35, 55),
                'hero_tag' => null,
                'force_windy' => false,
            ];
        }

        if ($dow === 2) {
            // Tuesday: Flagstaff climb repeats or tempo run
            if ($isWinter && $roll < 0.35) {
                return $this->indoorRideSession($dateStr, $hour, $minute, $intensity, $this->rng->getInt(45, 70));
            }

            if ($roll < 0.48) {
                return $this->routeSession($dateStr, $hour, $minute, 'road_flagstaff_climb', min(0.96, $intensity + 0.05));
            }

            return $this->routeSession(
                $dateStr,
                $hour,
                $minute,
                $roll < 0.78 ? 'run_creek_5k' : 'run_wonderland_8k',
                $intensity,
            );
        }

        if ($dow === 3) {
            // Wednesday: MTB singletrack or indoor trainer / trail run
            if ($isWinter && $roll < 0.45) {
                return $this->indoorRideSession($dateStr, $hour, $minute, $intensity, $this->rng->getInt(50, 75));
            }

            if ($roll < 0.42) {
                return $this->routeSession($dateStr, $hour, $minute, 'mtb_betasso_loop', $intensity);
            }

            if ($roll < 0.68) {
                return $this->routeSession($dateStr, $hour, $minute, 'mtb_marshall_mesa', $intensity);
            }

            return $this->routeSession($dateStr, $hour, $minute, 'run_sanitas_trail', $intensity);
        }

        if ($dow === 4) {
            // Thursday: rolling run or Flagstaff / Left Hand tempo ride
            if ($roll < 0.42) {
                return $this->routeSession($dateStr, $hour, $minute, 'run_chautauqua_10k', $intensity);
            }

            if ($roll < 0.68) {
                return $this->routeSession($dateStr, $hour, $minute, 'run_creek_5k', min(0.95, $intensity + 0.04));
            }

            return $this->routeSession($dateStr, $hour, $minute, 'road_flagstaff_climb', $intensity);
        }

        if ($dow === 5) {
            // Friday: easy walk, easy run, or light cardio
            if ($roll < 0.45) {
                return $this->routeSession($dateStr, $hour, $minute, 'walk_pearl_creek', 0.50);
            }

            if ($roll < 0.78) {
                return $this->routeSession($dateStr, $hour, $minute, 'run_wonderland_8k', 0.72);
            }

            return [
                'date' => $dateStr,
                'hour' => $hour,
                'minute' => $minute,
                'route_key' => null,
                'sport_category' => 'cardio',
                'sport_discipline' => null,
                'activity_type_key' => 'indoor_cardio',
                'is_indoor' => true,
                'name' => 'Aerobic Mobility & Core',
                'location' => null,
                'intensity' => 0.64,
                'duration_minutes' => $this->rng->getInt(30, 45),
                'hero_tag' => null,
                'force_windy' => false,
            ];
        }

        if ($dow === 6) {
            // Saturday: signature big endurance road / gravel / MTB ride
            if ($isDeloadWeek) {
                return $this->routeSession($dateStr, $hour, $minute, 'road_niwot_rolling', 0.74);
            }

            if (! $isWinter && ($weekIdx % 14) === 6) {
                return $this->routeSession($dateStr, 7, 0, 'road_ward_epic_century', 0.76, forceWindy: true);
            }

            if ($roll < 0.30) {
                return $this->routeSession($dateStr, $hour, $minute, 'road_niwot_rolling', $intensity);
            }

            if ($roll < 0.54) {
                return $this->routeSession($dateStr, $hour, $minute, 'road_sunshine_loop', $intensity);
            }

            if ($roll < 0.74) {
                return $this->routeSession($dateStr, $hour, $minute, 'road_lefthand_tempo', $intensity);
            }

            if ($roll < 0.88) {
                return $this->routeSession($dateStr, $hour, $minute, 'gravel_boulder_valley', $intensity);
            }

            return $this->routeSession($dateStr, $hour, $minute, 'mtb_walker_ranch', $intensity);
        }

        // Sunday: long run, MTB loop, or Flatirons hike
        if ($roll < 0.32) {
            return $this->routeSession($dateStr, $hour, $minute, 'run_chautauqua_10k', $intensity);
        }

        if ($roll < 0.50) {
            return $this->routeSession($dateStr, $hour, $minute, 'run_reservoir_half', 0.77);
        }

        if ($roll < 0.70) {
            return $this->routeSession($dateStr, $hour, $minute, 'mtb_betasso_loop', $intensity);
        }

        if ($roll < 0.85) {
            return $this->routeSession($dateStr, $hour, $minute, 'mtb_walker_ranch', $intensity);
        }

        return $this->routeSession($dateStr, $hour, $minute, 'hike_flatirons', 0.62);
    }

    /**
     * @return ScheduledSession
     */
    private function routeSession(
        string $dateStr,
        int $hour,
        int $minute,
        string $routeKey,
        float $intensity,
        bool $forceWindy = false,
    ): array {
        $def = $this->findRouteDefinition($routeKey);

        return [
            'date' => $dateStr,
            'hour' => $hour,
            'minute' => $minute,
            'route_key' => $routeKey,
            'sport_category' => $def['sport_category'],
            'sport_discipline' => $def['sport_discipline'],
            'activity_type_key' => $def['activity_type_key'],
            'is_indoor' => false,
            'name' => $def['name'],
            'location' => $def['location'],
            'intensity' => $intensity,
            'duration_minutes' => null,
            'hero_tag' => null,
            'force_windy' => $forceWindy,
        ];
    }

    /**
     * @return ScheduledSession
     */
    private function indoorRideSession(
        string $dateStr,
        int $hour,
        int $minute,
        float $intensity,
        int $durationMinutes,
    ): array {
        $names = ['Indoor Sweet Spot Intervals', 'Indoor Threshold Progression', 'Indoor Tempo Trainer'];
        $name = $names[$this->rng->getInt(0, count($names) - 1)];

        return [
            'date' => $dateStr,
            'hour' => $hour,
            'minute' => $minute,
            'route_key' => null,
            'sport_category' => 'biking',
            'sport_discipline' => 'road',
            'activity_type_key' => 'indoor_cycling',
            'is_indoor' => true,
            'name' => $name,
            'location' => null,
            'intensity' => $intensity,
            'duration_minutes' => $durationMinutes,
            'hero_tag' => null,
            'force_windy' => false,
        ];
    }

    /**
     * Generate 730 days of realistic, cross-correlated health telemetry (DailyMetric, Sleep, HRV,
     * VO2 Max, Weight, Fitness Age) including intraday Body Battery & HR curves for recent days.
     *
     * @param  list<ScheduledSession>  $schedule
     * @return array<string, DayHealthContext>
     */
    private function seedHealthHistory(
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        array $schedule,
    ): array {
        /** @var array<string, list<ScheduledSession>> $sessionsByDate */
        $sessionsByDate = [];

        foreach ($schedule as $session) {
            $sessionsByDate[$session['date']][] = $session;
        }

        $totalDays = (int) $startDate->diffInDays($endDate) + 1;
        /** @var array<string, DayHealthContext> $contextByDate */
        $contextByDate = [];

        /** @var list<int> $hrvWindow */
        $hrvWindow = [];
        /** @var list<int> $rhrWindow */
        $rhrWindow = [];

        $prevEveningLoadPenalty = 0.0;

        DB::transaction(function () use (
            $startDate,
            $totalDays,
            $sessionsByDate,
            &$contextByDate,
            &$hrvWindow,
            &$rhrWindow,
            &$prevEveningLoadPenalty,
        ): void {
            for ($dayIdx = 0; $dayIdx < $totalDays; $dayIdx++) {
                $date = $startDate->addDays($dayIdx);
                $dateStr = $date->toDateString();
                $progress = $dayIdx / max(1, $totalDays - 1);
                $isRecent = $dayIdx >= ($totalDays - 16);
                $daySessions = $sessionsByDate[$dateStr] ?? [];

                // Seasonal + gentle AR-style wave for sleep, HRV, RHR, weight, and VO2 max.
                $wave = sin($dayIdx / 19.0) * 0.6 + cos($dayIdx / 47.0) * 0.4;

                // Sleep hours: 6.0–9.0h, reduced slightly after hard evening workouts so Insights Panel 4 shows real signal.
                $sleepHours = $this->clamp(
                    7.45 + ($wave * 0.42) - ($prevEveningLoadPenalty * 0.55) + $this->randGaussian(0.0, 0.52),
                    5.4,
                    9.3,
                );
                $sleepSeconds = (int) round($sleepHours * 3600);
                $deepPct = $this->clamp(0.19 - ($prevEveningLoadPenalty * 0.035) + $this->randGaussian(0.0, 0.025), 0.11, 0.27);
                $remPct = $this->clamp(0.22 + $this->randGaussian(0.0, 0.025), 0.14, 0.30);
                $deepSeconds = (int) round($sleepSeconds * $deepPct);
                $remSeconds = (int) round($sleepSeconds * $remPct);
                $lightSeconds = max(3600, $sleepSeconds - $deepSeconds - $remSeconds);
                $awakeSeconds = (int) round($this->randFloat(14.0, 42.0) * 60);

                $sleepScore = (int) round($this->clamp(
                    52.0 + (($sleepHours - 5.5) * 14.5) + (($deepPct - 0.16) * 110.0) - ($prevEveningLoadPenalty * 9.0) + $this->randGaussian(0.0, 3.5),
                    42.0,
                    96.0,
                ));

                $wakeHour = $this->rng->getInt(6, 7);
                $wakeMinute = $this->rng->getInt(5, 50);
                $sleepEndLocal = $date->setTime($wakeHour, $wakeMinute, 0);
                $sleepStartLocal = $sleepEndLocal->subSeconds($sleepSeconds + $awakeSeconds);

                // HRV (ms): improves slightly over 2 years, dips after hard evening workouts & short sleep.
                $baselineCenter = 54.0 + ($progress * 6.0);
                $hrvNight = (int) round($this->clamp(
                    $baselineCenter + (($sleepHours - 7.3) * 4.5) - ($prevEveningLoadPenalty * 7.5) + ($wave * 3.0) + $this->randGaussian(0.0, 4.2),
                    34.0,
                    82.0,
                ));
                $hrvWindow[] = $hrvNight;

                if (count($hrvWindow) > 7) {
                    array_shift($hrvWindow);
                }

                $hrvWeeklyAvg = (int) round(array_sum($hrvWindow) / count($hrvWindow));
                $balLow = (int) round($baselineCenter - 7.0);
                $balHigh = (int) round($baselineCenter + 9.0);
                $lowUpper = $balLow - 5;

                $hrvStatus = match (true) {
                    $hrvWeeklyAvg >= $balLow && $hrvWeeklyAvg <= $balHigh => 'BALANCED',
                    $hrvWeeklyAvg < $lowUpper => 'LOW',
                    $hrvWeeklyAvg < $balLow => 'UNBALANCED',
                    default => 'BALANCED',
                };

                // Resting HR: trends gently down from 49 -> 45 bpm as aerobic fitness builds.
                $rhr = (int) round($this->clamp(
                    49.0 - ($progress * 3.5) - (($hrvNight - $baselineCenter) * 0.14) + ($prevEveningLoadPenalty * 2.0) + $this->randGaussian(0.0, 1.2),
                    41.0,
                    57.0,
                ));
                $rhrWindow[] = $rhr;

                if (count($rhrWindow) > 7) {
                    array_shift($rhrWindow);
                }

                $sevenDayRhr = (int) round(array_sum($rhrWindow) / count($rhrWindow));

                // Day activity impact on steps, calories, stress, Body Battery, and next night's evening penalty.
                $dayActiveMinutes = 0;
                $dayStepsFromWorkouts = 0;
                $eveningPenalty = 0.0;

                foreach ($daySessions as $s) {
                    $estMinutes = $s['duration_minutes'] ?? ($s['sport_category'] === 'biking' ? 95 : 55);
                    $dayActiveMinutes += $estMinutes;

                    if (in_array($s['sport_category'], ['running', 'walking', 'hiking'], true)) {
                        $dayStepsFromWorkouts += (int) round($estMinutes * ($s['sport_category'] === 'running' ? 165 : 108));
                    }

                    if ($s['hour'] >= 17 && $s['intensity'] >= 0.78) {
                        $eveningPenalty = max($eveningPenalty, ($s['intensity'] - 0.65) * 2.8);
                    } elseif ($s['hour'] >= 17) {
                        $eveningPenalty = max($eveningPenalty, 0.35);
                    }
                }

                $steps = (int) round($this->randFloat(5200, 9600) + $dayStepsFromWorkouts);
                $avgStress = (int) round($this->clamp(
                    24.0 + ($prevEveningLoadPenalty * 6.0) - (($sleepScore - 75.0) * 0.15) + $this->randGaussian(0.0, 4.5),
                    14.0,
                    48.0,
                ));
                $bbLow = (int) round($this->clamp(18.0 + $this->randGaussian(0.0, 5.0) - ($dayActiveMinutes * 0.04), 8.0, 32.0));
                $bbHigh = (int) round($this->clamp(58.0 + (($sleepScore - 50.0) * 0.85) + $this->randGaussian(0.0, 3.0), 58.0, 98.0));
                $bbCharged = max(25, $bbHigh - $bbLow + $this->rng->getInt(-4, 6));
                $bbDrained = max(20, (int) round(($bbHigh - $bbLow) * $this->randFloat(0.75, 1.05)));
                $bbCurrent = $dayIdx === ($totalDays - 1)
                    ? max($bbLow + 8, (int) round($bbHigh - ($bbDrained * 0.45)))
                    : max($bbLow, $bbHigh - $bbDrained);

                $bbSeries = $isRecent ? $this->buildBodyBatterySeries($date, $sleepEndLocal, $bbLow, $bbHigh, $bbCurrent, $dayIdx === ($totalDays - 1)) : null;
                $hrSeries = $isRecent ? $this->buildDailyHrSeries($date, $sleepEndLocal, $rhr, $daySessions, $dayIdx === ($totalDays - 1)) : null;

                $moderateMin = (int) round($dayActiveMinutes * 0.45 + $this->rng->getInt(4, 14));
                $vigorousMin = (int) round($dayActiveMinutes * 0.40);
                $activeCals = (int) round(320 + ($dayActiveMinutes * 9.5) + $this->randFloat(0, 140));
                $bmrCals = 1780;

                DailyMetric::query()->create([
                    'provider' => self::PROVIDER,
                    'calendar_date' => $dateStr,
                    'steps' => $steps,
                    'step_goal' => 10000,
                    'distance_meters' => round($steps * 0.78, 1),
                    'total_calories' => $bmrCals + $activeCals,
                    'active_calories' => $activeCals,
                    'bmr_calories' => $bmrCals,
                    'resting_hr' => $rhr,
                    'seven_day_avg_rhr' => $sevenDayRhr,
                    'min_hr' => max(38, $rhr - 4),
                    'max_hr' => $daySessions !== [] ? $this->rng->getInt(162, 186) : $this->rng->getInt(108, 126),
                    'avg_stress' => $avgStress,
                    'max_stress' => min(96, $avgStress + $this->rng->getInt(38, 56)),
                    'stress_qualifier' => $avgStress <= 25 ? 'CALM' : ($avgStress <= 35 ? 'BALANCED' : 'STRESSFUL'),
                    'rest_stress_seconds' => 42000,
                    'low_stress_seconds' => 18500,
                    'medium_stress_seconds' => 9200,
                    'high_stress_seconds' => 3600,
                    'body_battery_current' => $bbCurrent,
                    'body_battery_high' => $bbHigh,
                    'body_battery_low' => $bbLow,
                    'body_battery_charged' => $bbCharged,
                    'body_battery_drained' => $bbDrained,
                    'body_battery_sleep' => max(22, $bbHigh - $bbLow),
                    'moderate_intensity_minutes' => $moderateMin,
                    'vigorous_intensity_minutes' => $vigorousMin,
                    'intensity_minutes_goal' => 150,
                    'floors_ascended' => round($this->randFloat(8.0, 26.0), 1),
                    'floors_descended' => round($this->randFloat(7.0, 24.0), 1),
                    'floors_goal' => 10,
                    'avg_respiration' => round($this->clamp(13.6 + $this->randGaussian(0.0, 0.5), 12.2, 15.6), 1),
                    'min_respiration' => 11.0,
                    'max_respiration' => 21.0,
                    'avg_spo2' => round($this->clamp(96.4 + $this->randGaussian(0.0, 0.6), 94.5, 98.5), 1),
                    'lowest_spo2' => 92.0,
                    'latest_spo2' => 97.0,
                    'hydration_ml' => (int) round(2350 + ($dayActiveMinutes * 12) + $this->rng->getInt(-250, 350)),
                    'heart_rate_series' => $hrSeries,
                    'body_battery_series' => $bbSeries,
                    'day_duration_seconds' => $dayIdx === ($totalDays - 1) ? 54000 : 86400,
                ]);

                SleepRecord::query()->create([
                    'provider' => self::PROVIDER,
                    'calendar_date' => $dateStr,
                    'sleep_seconds' => $sleepSeconds,
                    'deep_seconds' => $deepSeconds,
                    'light_seconds' => $lightSeconds,
                    'rem_seconds' => $remSeconds,
                    'awake_seconds' => $awakeSeconds,
                    'nap_seconds' => 0,
                    'sleep_start_local' => $sleepStartLocal,
                    'sleep_end_local' => $sleepEndLocal,
                    'sleep_score' => $sleepScore,
                    'sleep_need_minutes' => 475,
                    'next_sleep_need_minutes' => $dayActiveMinutes >= 90 ? 495 : 475,
                ]);

                HrvRecord::query()->create([
                    'provider' => self::PROVIDER,
                    'calendar_date' => $dateStr,
                    'weekly_avg' => $hrvWeeklyAvg,
                    'last_night_avg' => $hrvNight,
                    'last_night_5min_high' => $hrvNight + $this->rng->getInt(16, 28),
                    'status' => $hrvStatus,
                    'baseline_low_upper' => $lowUpper,
                    'baseline_balanced_low' => $balLow,
                    'baseline_balanced_upper' => $balHigh,
                    'feedback_phrase' => $hrvStatus === 'BALANCED' ? 'HRV_BALANCED_2' : 'HRV_UNBALANCED_1',
                ]);

                // Weight measurement roughly every 3 days, trending smoothly from 75.8 kg -> 72.6 kg.
                if (($dayIdx % 3) === 0 || $dayIdx === ($totalDays - 1)) {
                    $weightKg = $this->clamp(
                        75.8 - ($progress * 3.1) + (sin($dayIdx / 35.0) * 0.45) + $this->randGaussian(0.0, 0.25),
                        71.5,
                        77.0,
                    );
                    $heightM = 1.79;
                    $bmi = round($weightKg / ($heightM * $heightM), 1);
                    $bodyFatPct = round($this->clamp(15.2 - ($progress * 2.1) + $this->randGaussian(0.0, 0.25), 11.8, 16.8), 1);

                    WeightMeasurement::query()->create([
                        'provider' => self::PROVIDER,
                        'external_id' => 'demo-weight-'.$dateStr,
                        'calendar_date' => $dateStr,
                        'measured_at' => $date->setTime(7, 5, 0)->utc(),
                        'weight_grams' => (int) round($weightKg * 1000),
                        'bmi' => $bmi,
                        'body_fat_pct' => $bodyFatPct,
                        'body_water_pct' => round(61.5 + ($progress * 1.1), 1),
                        'bone_mass_grams' => 3350,
                        'muscle_mass_grams' => (int) round($weightKg * (1.0 - ($bodyFatPct / 100.0)) * 0.54 * 1000),
                        'source_type' => 'INDEX_SCALE',
                    ]);
                }

                // VO2 Max updates weekly for both running & cycling (climbing from 51 -> 57).
                if (($dayIdx % 7) === 0 || $dayIdx === ($totalDays - 1)) {
                    $runVo2 = round($this->clamp(51.4 + ($progress * 4.9) + (sin($dayIdx / 50.0) * 0.5) + $this->randGaussian(0.0, 0.2), 49.0, 58.0), 1);
                    $bikeVo2 = round($this->clamp(53.2 + ($progress * 5.2) + (cos($dayIdx / 55.0) * 0.5) + $this->randGaussian(0.0, 0.2), 51.0, 60.0), 1);

                    Vo2MaxRecord::query()->create([
                        'provider' => self::PROVIDER,
                        'calendar_date' => $dateStr,
                        'sport' => 'running',
                        'sub_sport' => 'ROAD',
                        'vo2_max_precise' => $runVo2,
                        'vo2_max' => round($runVo2),
                    ]);

                    Vo2MaxRecord::query()->create([
                        'provider' => self::PROVIDER,
                        'calendar_date' => $dateStr,
                        'sport' => 'cycling',
                        'sub_sport' => 'ROAD',
                        'vo2_max_precise' => $bikeVo2,
                        'vo2_max' => round($bikeVo2),
                    ]);
                }

                // Fitness Age record every 4 days + final day.
                if (($dayIdx % 4) === 0 || $dayIdx === ($totalDays - 1)) {
                    $chronoAge = 36.0;
                    $fitAge = round($this->clamp(32.8 - ($progress * 3.4) + $this->randGaussian(0.0, 0.2), 28.0, 34.0), 2);

                    FitnessAgeRecord::query()->create([
                        'provider' => self::PROVIDER,
                        'calendar_date' => $dateStr,
                        'chronological_age' => $chronoAge,
                        'fitness_age' => $fitAge,
                        'previous_fitness_age' => round($fitAge + 0.35, 2),
                        'components' => [
                            'vigorousDaysAvg' => ['value' => round(3.8 + ($progress * 0.8), 1), 'stale' => false, 'numOfWeeksForIm' => 6],
                            'rhr' => ['value' => $sevenDayRhr, 'stale' => false],
                            'vigorousMinutesAvg' => ['value' => round(165.0 + ($progress * 45.0), 1), 'stale' => false, 'numOfWeeksForIm' => 6],
                            'bmi' => ['value' => round(23.2 - ($progress * 0.6), 1), 'stale' => false, 'lastMeasurementDate' => $dateStr],
                        ],
                    ]);
                }

                // Combined readiness factor (0.93 to 1.07) fed into next activity's Efficiency Factor & HR drift!
                $sleepEffect = ($sleepHours - 7.3) * 0.025;
                $hrvEffect = ($hrvNight - $baselineCenter) * 0.0028;
                $readinessFactor = $this->clamp(1.0 + $sleepEffect + $hrvEffect, 0.92, 1.08);

                $contextByDate[$dateStr] = [
                    'date' => $dateStr,
                    'sleep_hours' => $sleepHours,
                    'sleep_score' => $sleepScore,
                    'hrv' => $hrvNight,
                    'rhr' => $rhr,
                    'fitness_progress' => $progress,
                    'readiness_factor' => $readinessFactor,
                ];

                $prevEveningLoadPenalty = $eveningPenalty;
            }
        });

        return $contextByDate;
    }

    /**
     * Build a realistic intraday Body Battery curve: charges overnight during sleep, drains across the day.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function buildBodyBatterySeries(
        CarbonImmutable $date,
        CarbonImmutable $wakeLocal,
        int $low,
        int $high,
        int $endValue,
        bool $isToday,
    ): array {
        $dayStart = $date->startOfDay();
        $endTime = $isToday ? $date->setTime(15, 30, 0) : $date->setTime(23, 45, 0);
        $wakeTs = $wakeLocal->getTimestamp();
        $startTs = $dayStart->getTimestamp();
        $endTs = $endTime->getTimestamp();

        $series = [];
        $stepSeconds = 1200; // 20-minute samples

        for ($ts = $startTs; $ts <= $endTs; $ts += $stepSeconds) {
            if ($ts <= $wakeTs) {
                $frac = ($ts - $startTs) / max(1, $wakeTs - $startTs);
                $val = (int) round($low + (($high - $low) * pow($frac, 0.85)));
            } else {
                $frac = ($ts - $wakeTs) / max(1, $endTs - $wakeTs);
                $val = (int) round($high - (($high - $endValue) * pow($frac, 0.92)));
            }

            $series[] = [$ts * 1000, max(5, min(100, $val))];
        }

        return $series;
    }

    /**
     * Build a realistic intraday heart-rate series including a bump during scheduled workouts.
     *
     * @param  list<ScheduledSession>  $daySessions
     * @return list<array{0: int, 1: int}>
     */
    private function buildDailyHrSeries(
        CarbonImmutable $date,
        CarbonImmutable $wakeLocal,
        int $rhr,
        array $daySessions,
        bool $isToday,
    ): array {
        $startTs = $date->startOfDay()->getTimestamp();
        $endTs = ($isToday ? $date->setTime(15, 30, 0) : $date->setTime(23, 55, 0))->getTimestamp();
        $wakeTs = $wakeLocal->getTimestamp();

        $workoutWindows = [];

        foreach ($daySessions as $s) {
            $wStart = $date->setTime($s['hour'], $s['minute'], 0)->getTimestamp();
            $wEnd = $wStart + (($s['duration_minutes'] ?? 65) * 60);
            $workoutWindows[] = [$wStart, $wEnd, (int) round(130 + ($s['intensity'] * 38))];
        }

        $series = [];

        for ($ts = $startTs; $ts <= $endTs; $ts += 300) {
            $hr = $ts < $wakeTs
                ? $rhr + $this->rng->getInt(-2, 4)
                : $rhr + $this->rng->getInt(12, 26);

            foreach ($workoutWindows as [$wStart, $wEnd, $targetHr]) {
                if ($ts >= $wStart && $ts <= $wEnd) {
                    $hr = $targetHr + $this->rng->getInt(-8, 12);
                    break;
                }
            }

            $series[] = [$ts * 1000, max(38, min(self::ATHLETE_MAX_HR, $hr))];
        }

        return $series;
    }

    /**
     * Simulate a single activity (outdoor physics-driven GPS stream or indoor structured workout)
     * and persist its Activity, ActivityStream, ActivityWeather, BestEfforts, DerivedMetrics, and Gear.
     *
     * @param  ScheduledSession  $session
     * @param  array<string, CachedRoute>  $routes
     * @param  array<string, HourlyWeather>  $hourlyWeather
     * @param  DayHealthContext  $health
     */
    private function createSimulatedActivity(
        array $session,
        int $sequenceIndex,
        array $routes,
        array $hourlyWeather,
        array $health,
        BestEffortCalculator $bestEffortCalculator,
        ActivityDerivedMetricsService $derivedMetricsService,
        GearService $gearService,
    ): Activity {
        $startedLocal = CarbonImmutable::createFromFormat('Y-m-d', $session['date'], self::DEMO_TIMEZONE)
            ->setTime($session['hour'], $session['minute'], $this->rng->getInt(0, 50));
        $startedUtc = $startedLocal->utc();

        if ($session['is_indoor'] || $session['route_key'] === null) {
            return $this->createIndoorActivity(
                session: $session,
                sequenceIndex: $sequenceIndex,
                startedLocal: $startedLocal,
                startedUtc: $startedUtc,
                health: $health,
                derivedMetricsService: $derivedMetricsService,
                gearService: $gearService,
            );
        }

        $routeDef = $this->findRouteDefinition($session['route_key']);
        $baseRoute = $routes[$session['route_key']];
        $weatherHour = $this->resolveWeatherForTime($startedLocal, $hourlyWeather, $session['force_windy']);

        // Apply subtle smooth GPS jitter (~2.5 m) so every repeat is a distinct realistic GPS track
        // while staying cleanly snapped to the road and well inside RouteMatchingService tolerances.
        $jitteredPoints = $this->jitterRoutePoints($baseRoute['points']);

        $isCycling = $session['sport_category'] === 'biking';
        $isRunning = $session['sport_category'] === 'running';
        $isMtb = $isCycling && $session['sport_discipline'] === 'mtb';
        $isRoad = $isCycling && $session['sport_discipline'] === 'road';
        $isGravel = $isCycling && $session['sport_discipline'] === 'gravel';

        // Progressive fitness across the 2-year window: FTP 235W -> 278W; flat run threshold speed 3.55 -> 4.05 m/s.
        $ftp = round(235.0 + ($health['fitness_progress'] * 43.0), 1);
        $tempPenalty = $weatherHour['temp'] > 22.0 ? ($weatherHour['temp'] - 22.0) * 0.008 : 0.0;
        $effectiveReadiness = $health['readiness_factor'] * (1.0 - $tempPenalty);

        $sim = $this->simulateOutdoorStream(
            points: $jitteredPoints,
            sportCategory: $session['sport_category'],
            sportDiscipline: $session['sport_discipline'],
            intensity: $session['intensity'],
            ftp: $ftp,
            readiness: $effectiveReadiness,
            fitnessProgress: $health['fitness_progress'],
            weather: $weatherHour,
            desiredJumps: $isMtb ? $routeDef['jump_count'] : 0,
        );

        $durationSec = $sim['duration_seconds'];
        $distanceM = $sim['distance_meters'];
        $avgSpeed = $durationSec > 0.0 ? $distanceM / $durationSec : 0.0;
        $calories = (int) round(
            $sim['total_work_kj'] !== null
                ? ($sim['total_work_kj'] * 1.05)
                : ($durationSec / 60.0) * ($isRunning ? 11.8 : ($isCycling ? 9.5 : 5.6)) * $session['intensity'],
        );

        $trainingLoad = $sim['tss'] !== null
            ? round($sim['tss'] * $this->randFloat(0.95, 1.08), 1)
            : round(($durationSec / 3600.0) * (55.0 + ($session['intensity'] * 65.0)), 1);

        $aerobicTe = round($this->clamp(1.6 + ($trainingLoad / 42.0), 1.5, 5.0), 1);
        $anaerobicTe = round($this->clamp(($session['intensity'] - 0.72) * 7.5 + $this->randFloat(0.0, 0.6), 0.0, 3.6), 1);

        $powerZoneBounds = ($isRoad || $isGravel)
            ? [
                (int) round($ftp * 0.55),
                (int) round($ftp * 0.75),
                (int) round($ftp * 0.90),
                (int) round($ftp * 1.05),
                (int) round($ftp * 1.20),
                (int) round($ftp * 1.50),
            ]
            : null;

        $beginBb = $this->rng->getInt(68, 94);
        $bbDiff = -min($beginBb - 10, max(4, (int) round($trainingLoad * 0.32)));

        $activity = Activity::query()->create([
            'provider' => self::PROVIDER,
            'external_id' => sprintf('demo-%05d', $sequenceIndex),
            'name' => $session['name'],
            'garmin_name' => $session['name'],
            'activity_type_key' => $session['activity_type_key'],
            'sport_category' => $session['sport_category'],
            'garmin_sport_category' => $session['sport_category'],
            'sport_discipline' => $session['sport_discipline'],
            'is_indoor' => false,
            'started_at_local' => $startedLocal,
            'started_at_utc' => $startedUtc,
            'location_name' => $session['location'],
            'start_lat' => $jitteredPoints[0]['lat'],
            'start_lng' => $jitteredPoints[0]['lng'],
            'distance_meters' => round($distanceM, 2),
            'duration_seconds' => round($durationSec, 2),
            'moving_duration_seconds' => round($durationSec * 0.97, 2),
            'elapsed_duration_seconds' => round($durationSec * 1.03, 2),
            'elevation_gain' => round($sim['elevation_gain'], 1),
            'elevation_loss' => round($sim['elevation_loss'], 1),
            'min_elevation' => round($sim['min_elevation'], 1),
            'max_elevation' => round($sim['max_elevation'], 1),
            'avg_speed' => round($avgSpeed, 3),
            'max_speed' => round($sim['max_speed'], 3),
            'avg_grade_adjusted_speed' => $sim['avg_gap_speed'],
            'calories' => $calories,
            'bmr_calories' => (int) round(($durationSec / 3600.0) * 74.0),
            'sweat_loss_ml' => (int) round(($durationSec / 3600.0) * (620.0 + max(0.0, $weatherHour['temp'] - 10.0) * 28.0)),
            'avg_hr' => $sim['avg_hr'],
            'max_hr' => $sim['max_hr'],
            'avg_cadence' => $sim['avg_cadence'],
            'max_cadence' => $sim['max_cadence'],
            'avg_stride_length' => $sim['avg_stride_length'],
            'avg_vertical_oscillation' => $sim['avg_vertical_oscillation'],
            'avg_vertical_ratio' => $sim['avg_vertical_ratio'],
            'avg_ground_contact_time' => $sim['avg_ground_contact_time'],
            'steps' => $isRunning || $session['sport_category'] === 'walking' || $session['sport_category'] === 'hiking'
                ? (int) round(($sim['avg_cadence'] ?? 155.0) * ($durationSec / 60.0))
                : null,
            'aerobic_te' => $aerobicTe,
            'anaerobic_te' => $anaerobicTe,
            'training_load' => $trainingLoad,
            'training_effect_label' => $aerobicTe >= 4.0 ? 'VO2MAX' : ($aerobicTe >= 3.2 ? 'TEMPO' : 'AEROBIC_BASE'),
            'body_battery_diff' => $bbDiff,
            'begin_body_battery' => $beginBb,
            'end_body_battery' => $beginBb + $bbDiff,
            'hr_zone_1_seconds' => $sim['hr_zones'][1],
            'hr_zone_2_seconds' => $sim['hr_zones'][2],
            'hr_zone_3_seconds' => $sim['hr_zones'][3],
            'hr_zone_4_seconds' => $sim['hr_zones'][4],
            'hr_zone_5_seconds' => $sim['hr_zones'][5],
            'avg_power' => $sim['avg_power'],
            'max_power' => $sim['max_power'],
            'normalized_power' => $sim['normalized_power'],
            'ftp' => ($isRoad || $isGravel) ? $ftp : null,
            'threshold_power' => ($isRoad || $isGravel) ? $ftp : null,
            'tss' => $sim['tss'],
            'intensity_factor' => $sim['intensity_factor'],
            'total_work' => $sim['total_work_kj'],
            'left_balance' => ($isRoad || $isGravel) ? round($this->randFloat(49.4, 51.2), 1) : null,
            'avg_left_torque_effectiveness' => ($isRoad || $isGravel) ? round($this->randFloat(73.0, 81.0), 1) : null,
            'avg_right_torque_effectiveness' => ($isRoad || $isGravel) ? round($this->randFloat(72.0, 80.0), 1) : null,
            'avg_left_pedal_smoothness' => ($isRoad || $isGravel) ? round($this->randFloat(21.0, 26.0), 1) : null,
            'avg_right_pedal_smoothness' => ($isRoad || $isGravel) ? round($this->randFloat(20.5, 25.5), 1) : null,
            'max_20min_power' => $sim['power_curve'][1200] ?? null,
            'power_zone_1_seconds' => $sim['power_zones'][1] ?? null,
            'power_zone_2_seconds' => $sim['power_zones'][2] ?? null,
            'power_zone_3_seconds' => $sim['power_zones'][3] ?? null,
            'power_zone_4_seconds' => $sim['power_zones'][4] ?? null,
            'power_zone_5_seconds' => $sim['power_zones'][5] ?? null,
            'power_zone_6_seconds' => $sim['power_zones'][6] ?? null,
            'power_zone_7_seconds' => $sim['power_zones'][7] ?? null,
            'power_zone_bounds' => $powerZoneBounds,
            'power_curve' => $sim['power_curve'] !== [] ? $sim['power_curve'] : null,
            'wind_enabled' => true,
            'grit' => $sim['grit'],
            'avg_flow' => $sim['avg_flow'],
            'jump_count' => $sim['jumps'] !== [] ? count($sim['jumps']) : null,
            'unpaved_pct' => $routeDef['unpaved_pct'],
            'workout_feel' => $health['sleep_score'] >= 78 ? 80 : 60,
            'workout_rpe' => (int) round($this->clamp($session['intensity'] * 8.5, 3.0, 9.0)),
            'execution_score' => $this->rng->getInt(78, 96),
            'begin_stamina' => 100,
            'end_stamina' => $sim['end_stamina'],
            'min_stamina' => $sim['end_stamina'],
            'avg_respiration_rate' => $sim['avg_respiration'],
            'min_respiration_rate' => max(12.0, round($sim['avg_respiration'] - 9.0, 1)),
            'max_respiration_rate' => round($sim['avg_respiration'] + 12.0, 1),
            'avg_temperature' => round($weatherHour['temp'] + 2.5, 1),
            'min_temperature' => round($weatherHour['temp'] + 0.5, 1),
            'max_temperature' => round($weatherHour['temp'] + 5.0, 1),
            'moderate_intensity_minutes' => (int) round(($durationSec / 60.0) * 0.35),
            'vigorous_intensity_minutes' => (int) round(($durationSec / 60.0) * 0.60),
            'run_duration_seconds' => $sim['run_duration_seconds'],
            'walk_duration_seconds' => $sim['walk_duration_seconds'],
            'idle_duration_seconds' => $sim['idle_duration_seconds'],
            'is_favorite' => $session['hero_tag'] !== null,
            'garmin_is_favorite' => $session['hero_tag'] !== null,
            'is_pr' => false,
            'is_manual' => false,
            'streams_synced_at' => CarbonImmutable::now('UTC'),
        ]);

        $stream = ActivityStream::query()->create([
            'activity_id' => $activity->id,
            'series' => $sim['series'],
            'polyline' => $sim['polyline'],
            'laps' => $sim['laps'],
            'km_splits' => $sim['km_splits'],
            'run_walk_segments' => $sim['run_walk_segments'] !== [] ? $sim['run_walk_segments'] : null,
            'jumps' => $sim['jumps'] !== [] ? $sim['jumps'] : null,
        ]);

        $this->persistActivityWeather($activity, $sim['series'], $startedLocal, $hourlyWeather, $session['force_windy']);

        $bestEffortCalculator->syncForActivity($activity, $sim['series']);
        $derivedMetricsService->computeAndStore($activity, $stream);
        $gearService->autoAssignDefaultGear($activity);

        return $activity;
    }

    /**
     * Simulate an indoor cycling or strength/cardio activity (no GPS polyline, full HR/power/cadence streams).
     *
     * @param  ScheduledSession  $session
     * @param  DayHealthContext  $health
     */
    private function createIndoorActivity(
        array $session,
        int $sequenceIndex,
        CarbonImmutable $startedLocal,
        CarbonImmutable $startedUtc,
        array $health,
        ActivityDerivedMetricsService $derivedMetricsService,
        GearService $gearService,
    ): Activity {
        $isIndoorBike = $session['sport_category'] === 'biking';
        $durationSec = (float) (($session['duration_minutes'] ?? 45) * 60);
        $ftp = round(235.0 + ($health['fitness_progress'] * 43.0), 1);

        $stepSec = 10;
        $series = [];
        $cumDist = 0.0;
        $hr = (float) ($health['rhr'] + 32);
        $hrSum = 0.0;
        $maxHr = 0;
        $powerSum = 0.0;
        $maxPower = 0;
        $cadSum = 0.0;
        $maxCad = 0;
        $powerSamples = [];
        $hrZones = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0];
        $powerZones = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0, 6 => 0.0, 7 => 0.0];

        for ($t = 0; $t <= (int) $durationSec; $t += $stepSec) {
            $frac = $t / max(1.0, $durationSec);
            $intervalBoost = ($frac > 0.15 && $frac < 0.85 && (((int) ($t / 300)) % 2) === 1) ? 1.18 : 0.86;

            $power = null;
            $cadence = null;
            $speed = 0.0;

            if ($isIndoorBike) {
                $targetP = $ftp * (0.68 + (($session['intensity'] - 0.65) * 0.55)) * $intervalBoost;
                $power = (int) round(max(85.0, $targetP + $this->randGaussian(0.0, 12.0)));
                $cadence = (int) round($this->clamp(86.0 + ($intervalBoost > 1.0 ? 5.0 : -2.0) + $this->randGaussian(0.0, 2.5), 74.0, 102.0));
                $speed = round(max(4.5, pow($power / 0.24, 1.0 / 3.0)), 3);
                $cumDist += $speed * $stepSec;
                $powerSum += $power;
                $maxPower = max($maxPower, $power);
                $powerSamples[] = (float) $power;
                $cadSum += $cadence;
                $maxCad = max($maxCad, $cadence);
                $pz = $this->classifyPowerZone((float) $power, $ftp);
                $powerZones[$pz] += $stepSec;
            }

            $targetHr = $isIndoorBike
                ? ($health['rhr'] + ((self::ATHLETE_MAX_HR - $health['rhr']) * $this->clamp(0.42 + ($session['intensity'] * 0.42 * $intervalBoost) + ($frac * 0.05), 0.35, 0.94)))
                : ($health['rhr'] + ((self::ATHLETE_MAX_HR - $health['rhr']) * $this->clamp(0.32 + ($session['intensity'] * 0.34 * $intervalBoost), 0.28, 0.78)));

            $hr += ($targetHr - $hr) * 0.22 + $this->randGaussian(0.0, 1.0);
            $hrInt = (int) round($this->clamp($hr, 75.0, self::ATHLETE_MAX_HR - 4.0));
            $hrSum += $hrInt;
            $maxHr = max($maxHr, $hrInt);
            $hz = $this->classifyHrZone($hrInt);
            $hrZones[$hz] += $stepSec;

            $series[] = [
                'time' => (float) $t,
                'distance' => $isIndoorBike ? round($cumDist, 2) : 0.0,
                'altitude' => null,
                'speed' => $isIndoorBike ? $speed : null,
                'hr' => $hrInt,
                'cadence' => $cadence,
                'power' => $power,
                'grade' => 0.0,
                'lat' => null,
                'lng' => null,
                'stamina' => (int) round(max(35.0, 100.0 - ($frac * 45.0))),
                'stamina_potential' => (int) round(max(45.0, 100.0 - ($frac * 35.0))),
                'temperature' => 21.0,
            ];
        }

        $sampleCount = max(1, count($series));
        $avgHr = (int) round($hrSum / $sampleCount);
        $avgPower = $isIndoorBike ? round($powerSum / $sampleCount, 1) : null;
        $np = $isIndoorBike ? $this->computeNormalizedPower($powerSamples, $stepSec) : null;
        $if = ($np !== null && $ftp > 0.0) ? round($np / $ftp, 3) : null;
        $tss = ($np !== null && $if !== null) ? round(($durationSec * $np * $if) / ($ftp * 36.0), 1) : null;
        $powerCurve = $isIndoorBike ? $this->computePowerCurve($powerSamples, $stepSec, $maxPower) : null;

        $activity = Activity::query()->create([
            'provider' => self::PROVIDER,
            'external_id' => sprintf('demo-%05d', $sequenceIndex),
            'name' => $session['name'],
            'garmin_name' => $session['name'],
            'activity_type_key' => $session['activity_type_key'],
            'sport_category' => $session['sport_category'],
            'garmin_sport_category' => $session['sport_category'],
            'sport_discipline' => $session['sport_discipline'],
            'is_indoor' => true,
            'started_at_local' => $startedLocal,
            'started_at_utc' => $startedUtc,
            'distance_meters' => round($cumDist, 2),
            'duration_seconds' => $durationSec,
            'moving_duration_seconds' => $durationSec,
            'elapsed_duration_seconds' => $durationSec,
            'avg_speed' => $isIndoorBike ? round($cumDist / max(1.0, $durationSec), 3) : null,
            'calories' => (int) round(($durationSec / 60.0) * ($isIndoorBike ? 10.5 : 6.8)),
            'avg_hr' => $avgHr,
            'max_hr' => $maxHr,
            'avg_cadence' => $isIndoorBike ? round($cadSum / $sampleCount, 1) : null,
            'max_cadence' => $isIndoorBike ? (float) $maxCad : null,
            'aerobic_te' => $isIndoorBike ? 3.4 : 2.2,
            'anaerobic_te' => $isIndoorBike ? 1.1 : 0.6,
            'training_load' => $tss ?? round(($durationSec / 3600.0) * 58.0, 1),
            'training_effect_label' => $isIndoorBike ? 'TEMPO' : 'AEROBIC_BASE',
            'hr_zone_1_seconds' => $hrZones[1],
            'hr_zone_2_seconds' => $hrZones[2],
            'hr_zone_3_seconds' => $hrZones[3],
            'hr_zone_4_seconds' => $hrZones[4],
            'hr_zone_5_seconds' => $hrZones[5],
            'avg_power' => $avgPower,
            'max_power' => $isIndoorBike ? (float) $maxPower : null,
            'normalized_power' => $np,
            'ftp' => $isIndoorBike ? $ftp : null,
            'threshold_power' => $isIndoorBike ? $ftp : null,
            'tss' => $tss,
            'intensity_factor' => $if,
            'total_work' => $avgPower !== null ? round(($avgPower * $durationSec) / 1000.0, 1) : null,
            'left_balance' => $isIndoorBike ? round($this->randFloat(49.5, 50.8), 1) : null,
            'max_20min_power' => $powerCurve[1200] ?? null,
            'power_zone_1_seconds' => $isIndoorBike ? $powerZones[1] : null,
            'power_zone_2_seconds' => $isIndoorBike ? $powerZones[2] : null,
            'power_zone_3_seconds' => $isIndoorBike ? $powerZones[3] : null,
            'power_zone_4_seconds' => $isIndoorBike ? $powerZones[4] : null,
            'power_zone_5_seconds' => $isIndoorBike ? $powerZones[5] : null,
            'power_zone_6_seconds' => $isIndoorBike ? $powerZones[6] : null,
            'power_zone_7_seconds' => $isIndoorBike ? $powerZones[7] : null,
            'power_curve' => $powerCurve,
            'is_favorite' => false,
            'garmin_is_favorite' => false,
            'is_pr' => false,
            'is_manual' => false,
            'streams_synced_at' => CarbonImmutable::now('UTC'),
        ]);

        $stream = ActivityStream::query()->create([
            'activity_id' => $activity->id,
            'series' => $series,
            'polyline' => [],
            'laps' => [],
            'km_splits' => [],
        ]);

        $derivedMetricsService->computeAndStore($activity, $stream);
        $gearService->autoAssignDefaultGear($activity);

        return $activity;
    }

    /**
     * Physics & physiology telemetry simulator across a real OSM route geometry.
     *
     * @param  list<RoutePoint>  $points
     * @param  HourlyWeather  $weather
     * @return array{
     *     series: list<DemoSeriesPoint>,
     *     polyline: list<array{0: float, 1: float}>,
     *     laps: list<array<string, mixed>>,
     *     km_splits: list<array<string, mixed>>,
     *     run_walk_segments: list<array<string, mixed>>,
     *     jumps: list<array<string, mixed>>,
     *     duration_seconds: float,
     *     distance_meters: float,
     *     elevation_gain: float,
     *     elevation_loss: float,
     *     min_elevation: float,
     *     max_elevation: float,
     *     max_speed: float,
     *     avg_gap_speed: ?float,
     *     avg_hr: int,
     *     max_hr: int,
     *     avg_cadence: ?float,
     *     max_cadence: ?float,
     *     avg_stride_length: ?float,
     *     avg_vertical_oscillation: ?float,
     *     avg_vertical_ratio: ?float,
     *     avg_ground_contact_time: ?float,
     *     avg_power: ?float,
     *     max_power: ?float,
     *     normalized_power: ?float,
     *     tss: ?float,
     *     intensity_factor: ?float,
     *     total_work_kj: ?float,
     *     power_curve: array<int, int>,
     *     hr_zones: array<int, float>,
     *     power_zones: array<int, float>,
     *     grit: ?float,
     *     avg_flow: ?float,
     *     end_stamina: int,
     *     avg_respiration: float,
     *     run_duration_seconds: ?float,
     *     walk_duration_seconds: ?float,
     *     idle_duration_seconds: ?float
     * }
     */
    private function simulateOutdoorStream(
        array $points,
        string $sportCategory,
        ?string $sportDiscipline,
        float $intensity,
        float $ftp,
        float $readiness,
        float $fitnessProgress,
        array $weather,
        int $desiredJumps,
    ): array {
        $isCycling = $sportCategory === 'biking';
        $isRoad = $isCycling && $sportDiscipline === 'road';
        $isGravel = $isCycling && $sportDiscipline === 'gravel';
        $isMtb = $isCycling && $sportDiscipline === 'mtb';
        $isRunning = $sportCategory === 'running';
        $isWalking = $sportCategory === 'walking';
        $isHiking = $sportCategory === 'hiking';
        $hasPowerMeter = $isRoad || $isGravel || $isRunning;

        $totalDistance = $points[count($points) - 1]['dist'];

        // Choose sampling interval (< 15s always so pause detection in ActivityDerivedMetricsService never falsely triggers).
        $stepSec = match (true) {
            $totalDistance >= 100_000.0 => 10.0,
            $totalDistance >= 45_000.0 => 8.0,
            default => 5.0,
        };

        $windSpeedMs = $weather['wind_speed'] / 3.6;
        $windFromRad = deg2rad((float) $weather['wind_dir']);
        $totalMassKg = self::ATHLETE_WEIGHT_KG + ($isRoad ? 8.2 : ($isGravel ? 9.6 : 12.8));

        // Base flat run speed scales with fitness (3.15 -> 3.65 m/s at endurance effort, faster at high intensity).
        $baseFlatRunSpeed = (2.85 + ($fitnessProgress * 0.42) + (($intensity - 0.70) * 1.65)) * $readiness;

        // First-order physiological state.
        $hr = (float) (self::ATHLETE_RESTING_HR + 36);
        $dist = 0.0;
        $time = 0.0;
        $pointIdx = 0;
        $pointCount = count($points);

        $series = [];
        $polyline = [];
        $powerSamples = [];
        $hrZones = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0];
        $powerZones = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0, 6 => 0.0, 7 => 0.0];

        $elevGain = 0.0;
        $elevLoss = 0.0;
        $minEle = $points[0]['ele'];
        $maxEle = $points[0]['ele'];
        $prevEle = $points[0]['ele'];

        $maxSpeed = 0.0;
        $gapSpeedSum = 0.0;
        $gapCount = 0;
        $hrSum = 0.0;
        $maxHr = 0;
        $cadSum = 0.0;
        $cadCount = 0;
        $maxCad = 0;
        $powerSum = 0.0;
        $powerCount = 0;
        $maxPower = 0;
        $strideSum = 0.0;
        $voSum = 0.0;
        $vrSum = 0.0;
        $gctSum = 0.0;
        $respSum = 0.0;
        $gritTotal = 0.0;
        $flowSum = 0.0;
        $flowCount = 0;

        /** @var list<array{idx: int, grade: float, speed: float, time: float, lat: float, lng: float}> $downhillCandidates */
        $downhillCandidates = [];

        $noisePhase = $this->randFloat(0.0, 6.28);
        $fatigueRate = (1.08 - $readiness) * 0.45 + 0.025; // lower readiness => slightly higher cardiac drift
        $stamina = 100.0;

        while ($dist < $totalDistance && count($series) < 5000) {
            $pos = $this->interpolateRouteAtDistance($points, $dist, $pointIdx);
            $pointIdx = $pos['idx'];

            // Look ahead ~50m for realistic smoothed terrain grade & bearing.
            $ahead = $this->interpolateRouteAtDistance($points, min($totalDistance, $dist + 55.0), $pointIdx);
            $spanDist = max(10.0, $ahead['dist'] - $pos['dist']);
            $grade = $this->clamp(($ahead['ele'] - $pos['ele']) / $spanDist, -0.22, 0.24);
            $gradePct = round($grade * 100.0, 1);

            $bearingRad = $this->bearingRadians($pos['lat'], $pos['lng'], $ahead['lat'], $ahead['lng']);
            // Positive headwind when riding/running into the wind direction.
            $headwindMs = $windSpeedMs * cos($bearingRad - $windFromRad);

            $frac = $dist / max(1.0, $totalDistance);
            $microWave = sin(($time / 95.0) + $noisePhase) * 0.05 + cos(($time / 230.0) + $noisePhase) * 0.03;

            $power = null;
            $cadence = null;
            $gapSpeed = null;
            $strideLength = null;
            $verticalOsc = null;
            $verticalRatio = null;
            $gct = null;
            $leftBalance = null;
            $gritInstant = null;
            $flowInstant = null;

            if ($isCycling) {
                $crr = $isRoad ? 0.0042 : ($isGravel ? 0.0075 : 0.0135);
                $cda = $isRoad ? 0.31 : ($isGravel ? 0.36 : 0.44);

                // Climbs draw higher power; steep descents drop to coasting/soft-pedaling.
                $gradePowerBoost = $grade > 0.015
                    ? min(0.30, $grade * 3.1)
                    : ($grade < -0.04 ? -0.58 : ($grade < -0.02 ? -0.26 : 0.0));

                $basePower = $ftp * (0.58 + (($intensity - 0.65) * 0.72)) * (1.0 + $gradePowerBoost + $microWave);
                $basePower *= (1.0 - ($frac * 0.06)); // gentle late-ride fade

                if ($isMtb && $grade < -0.04) {
                    $basePower *= 0.42;
                }

                $targetPower = max(0.0, $basePower + $this->randGaussian(0.0, 11.0));
                $speed = $this->solveCyclingSpeed($targetPower, $grade, $headwindMs, $totalMassKg, $crr, $cda);

                if ($isMtb) {
                    // Singletrack cornering & trail surface speed cap.
                    $speed = $this->clamp($speed * ($grade < -0.02 ? 0.76 : 0.84), 1.4, 11.2);
                } else {
                    $speed = $this->clamp($speed, 1.8, 19.5);
                }

                if ($isRoad || $isGravel) {
                    $power = (int) round($grade < -0.055 ? max(0.0, $targetPower * 0.35) : $targetPower);
                    $cadence = $power < 35
                        ? 0
                        : (int) round($this->clamp(86.0 - ($grade * 140.0) + $this->randGaussian(0.0, 3.0), 62.0, 104.0));
                    $leftBalance = round($this->clamp(50.2 + $this->randGaussian(0.0, 1.1), 47.0, 53.5), 1);
                }

                if ($isMtb) {
                    $absGrade = abs($grade);
                    $gritInstant = round($this->clamp(0.8 + ($absGrade * 34.0) + ($speed * 0.25) + $this->randFloat(0.0, 1.6), 0.3, 14.0), 1);
                    $flowInstant = round($this->clamp(4.2 + ($absGrade * 22.0) + $this->randFloat(0.2, 3.2), 2.5, 13.5), 1);
                    $gritTotal += ($gritInstant * $stepSec) / 95.0;
                    $flowSum += $flowInstant;
                    $flowCount++;
                }

                $effortFraction = $this->clamp(($targetPower / max(120.0, $ftp)) * 0.78, 0.30, 1.05);
            } elseif ($isRunning) {
                // Minetti grade-cost approximation for realistic uphill slowdown & downhill pickup.
                $gradeCost = 1.0 + ($grade * 4.4) + ($grade * $grade * 14.0);
                $gradeCost = $this->clamp($gradeCost, 0.78, 2.35);

                $windFactor = 1.0 - ($headwindMs * 0.012);
                $paceDrift = 1.0 - ($frac * 0.035);
                $speed = $this->clamp(($baseFlatRunSpeed * $windFactor * $paceDrift * (1.0 + $microWave)) / $gradeCost, 1.55, 5.4);

                // Brief steep-trail or warm-up walk moment for run/walk segmentation.
                if ($time < 15.0) {
                    $speed = min($speed, 1.75);
                }

                $gapSpeed = round($speed * $gradeCost, 3);
                $gapSpeedSum += $gapSpeed;
                $gapCount++;

                // Running power (Stryd/Garmin style ~1.04 W/kg per m/s on flat, scaled by grade cost).
                $power = (int) round($this->clamp(self::ATHLETE_WEIGHT_KG * $speed * $gradeCost * 1.05 + $this->randGaussian(0.0, 8.0), 160.0, 520.0));
                $cadence = (int) round($this->clamp(164.0 + (($speed - 2.6) * 10.5) + $this->randGaussian(0.0, 2.0), 152.0, 192.0));
                $strideLength = round($speed * 60.0 / max(120, $cadence), 3);
                $verticalOsc = round($this->clamp(8.9 - (($cadence - 165.0) * 0.055) + $this->randGaussian(0.0, 0.25), 6.2, 10.5), 2);
                $verticalRatio = round(($verticalOsc / max(0.5, $strideLength * 100.0)) * 100.0, 2);
                $gct = round($this->clamp(268.0 - (($speed - 2.6) * 24.0) + $this->randGaussian(0.0, 4.0), 205.0, 295.0), 1);

                $strideSum += $strideLength;
                $voSum += $verticalOsc;
                $vrSum += $verticalRatio;
                $gctSum += $gct;

                $effortFraction = $this->clamp((($gapSpeed - 1.8) / 2.6) * 0.85, 0.45, 1.0);
            } else {
                // Hiking or walking
                $baseWalk = $isHiking ? 1.32 : 1.48;
                $gradeFactor = $this->clamp(1.0 + ($grade * 3.8), 0.72, 2.2);
                $speed = $this->clamp(($baseWalk * (1.0 + $microWave)) / $gradeFactor, 0.65, 2.0);
                $cadence = (int) round($this->clamp(108.0 + ($speed * 14.0) + $this->randGaussian(0.0, 2.5), 88.0, 132.0));
                $effortFraction = $this->clamp(0.32 + ($grade * 1.4) + ($isHiking ? 0.14 : 0.0), 0.25, 0.75);
            }

            // Heart rate with 1st-order physiological lag + cardiac drift (tightly coupled to sleep/HRV readiness).
            $driftBpm = $frac * (5.0 + ($fatigueRate * 26.0));
            $targetHr = self::ATHLETE_RESTING_HR + ((self::ATHLETE_MAX_HR - self::ATHLETE_RESTING_HR) * $this->clamp(0.28 + ($effortFraction * 0.66), 0.25, 0.96)) + $driftBpm;
            $hrTau = 32.0; // seconds time constant for realistic cardiac lag on hills
            $hr += ($targetHr - $hr) * (1.0 - exp(-$stepSec / $hrTau)) + $this->randGaussian(0.0, 0.8);
            $hrInt = (int) round($this->clamp($hr, 72.0, self::ATHLETE_MAX_HR - 2.0));

            $respRate = round($this->clamp(14.0 + (($hrInt - 80.0) * 0.24) + $this->randGaussian(0.0, 0.6), 13.0, 48.0), 1);
            $stamina = max(14.0, $stamina - (($effortFraction * $stepSec) / ($totalDistance > 80_000.0 ? 310.0 : 120.0)));
            $tempSample = round($weather['temp'] + 2.0 + ($frac * 2.2) - (($pos['ele'] - $points[0]['ele']) * 0.006), 1);

            $dEle = $pos['ele'] - $prevEle;

            if ($dEle > 0.25) {
                $elevGain += $dEle;
            } elseif ($dEle < -0.25) {
                $elevLoss += abs($dEle);
            }

            $prevEle = $pos['ele'];
            $minEle = min($minEle, $pos['ele']);
            $maxEle = max($maxEle, $pos['ele']);
            $maxSpeed = max($maxSpeed, $speed);
            $hrSum += $hrInt;
            $maxHr = max($maxHr, $hrInt);
            $respSum += $respRate;

            $hz = $this->classifyHrZone($hrInt);
            $hrZones[$hz] += $stepSec;

            if ($cadence !== null && $cadence > 0) {
                $cadSum += $cadence;
                $cadCount++;
                $maxCad = max($maxCad, $cadence);
            }

            if ($power !== null) {
                $powerSum += $power;
                $powerCount++;
                $maxPower = max($maxPower, $power);
                $powerSamples[] = (float) $power;

                if ($isRoad || $isGravel) {
                    $pz = $this->classifyPowerZone((float) $power, $ftp);
                    $powerZones[$pz] += $stepSec;
                }
            }

            $pointRecord = [
                'time' => round($time, 1),
                'distance' => round($dist, 2),
                'altitude' => round($pos['ele'], 1),
                'speed' => round($speed, 3),
                'hr' => $hrInt,
                'cadence' => $cadence,
                'power' => $power,
                'grade' => $gradePct,
                'lat' => round($pos['lat'], 6),
                'lng' => round($pos['lng'], 6),
                'stamina' => (int) round($stamina),
                'stamina_potential' => (int) round(min(100.0, $stamina + 6.0)),
                'respiration' => $respRate,
                'temperature' => $tempSample,
            ];

            if ($gapSpeed !== null) {
                $pointRecord['gap_speed'] = $gapSpeed;
            }

            if ($strideLength !== null) {
                $pointRecord['stride_length'] = $strideLength;
                $pointRecord['vertical_oscillation'] = $verticalOsc;
                $pointRecord['vertical_ratio'] = $verticalRatio;
                $pointRecord['gct'] = $gct;
            }

            if ($leftBalance !== null) {
                $pointRecord['left_balance'] = $leftBalance;
            }

            if ($gritInstant !== null) {
                $pointRecord['grit'] = $gritInstant;
                $pointRecord['flow'] = $flowInstant;
            }

            $series[] = $pointRecord;
            $polyline[] = [round($pos['lat'], 5), round($pos['lng'], 5)];

            if ($isMtb && $desiredJumps > 0 && $grade < -0.025 && $speed >= 4.2 && $frac > 0.25 && $frac < 0.92) {
                $downhillCandidates[] = [
                    'idx' => count($series) - 1,
                    'grade' => $grade,
                    'speed' => $speed,
                    'time' => $time,
                    'lat' => $pos['lat'],
                    'lng' => $pos['lng'],
                ];
            }

            $dist += $speed * $stepSec;
            $time += $stepSec;
        }

        // Compute MTB jumps spaced along genuine steep downhill trail points.
        $jumps = $this->buildMtbJumps($downhillCandidates, $desiredJumps, $series);

        // Compute km_splits & laps directly from the simulated series.
        $kmSplits = $this->computeKmSplits($series);
        $laps = $this->computeLaps($series, $isCycling ? 5000.0 : 1000.0);

        // Compute realistic run/walk segments for running activities.
        $runWalkSegments = [];
        $runDur = null;
        $walkDur = null;
        $idleDur = null;

        if ($isRunning && $time > 120.0) {
            $walkWarmup = min(20.0, round($time * 0.01, 1));
            $midWalkStart = round($time * 0.62, 1);
            $midWalkDur = round($this->randFloat(18.0, 42.0), 1);
            $runPart1Dur = max(60.0, $midWalkStart - $walkWarmup);
            $runPart2Start = $midWalkStart + $midWalkDur;
            $runPart2Dur = max(60.0, $time - $runPart2Start);

            $runWalkSegments = [
                [
                    'type' => 'walk',
                    'start_time' => 0.0,
                    'end_time' => $walkWarmup,
                    'duration_seconds' => $walkWarmup,
                    'distance_meters' => round($walkWarmup * 1.45, 2),
                ],
                [
                    'type' => 'run',
                    'start_time' => $walkWarmup,
                    'end_time' => $midWalkStart,
                    'duration_seconds' => round($runPart1Dur, 2),
                    'distance_meters' => round($totalDistance * 0.61, 2),
                ],
                [
                    'type' => 'walk',
                    'start_time' => $midWalkStart,
                    'end_time' => $runPart2Start,
                    'duration_seconds' => $midWalkDur,
                    'distance_meters' => round($midWalkDur * 1.4, 2),
                ],
                [
                    'type' => 'run',
                    'start_time' => $runPart2Start,
                    'end_time' => round($time, 1),
                    'duration_seconds' => round($runPart2Dur, 2),
                    'distance_meters' => round($totalDistance * 0.37, 2),
                ],
            ];
            $runDur = round($runPart1Dur + $runPart2Dur, 1);
            $walkDur = round($walkWarmup + $midWalkDur, 1);
            $idleDur = round($this->randFloat(5.0, 28.0), 1);
        }

        $sampleCount = max(1, count($series));
        $avgPower = $powerCount > 0 ? round($powerSum / $powerCount, 1) : null;
        $np = ($isRoad || $isGravel) && $powerSamples !== [] ? $this->computeNormalizedPower($powerSamples, (int) $stepSec) : null;
        $if = ($np !== null && $ftp > 0.0) ? round($np / $ftp, 3) : null;
        $tss = ($np !== null && $if !== null) ? round(($time * $np * $if) / ($ftp * 36.0), 1) : null;
        $totalWorkKj = ($hasPowerMeter && $avgPower !== null) ? round(($avgPower * $time) / 1000.0, 1) : null;
        $powerCurve = ($isRoad || $isGravel) && $powerSamples !== [] ? $this->computePowerCurve($powerSamples, (int) $stepSec, $maxPower) : [];

        return [
            'series' => $series,
            'polyline' => $polyline,
            'laps' => $laps,
            'km_splits' => $kmSplits,
            'run_walk_segments' => $runWalkSegments,
            'jumps' => $jumps,
            'duration_seconds' => round($time, 1),
            'distance_meters' => round($totalDistance, 1),
            'elevation_gain' => round($elevGain, 1),
            'elevation_loss' => round($elevLoss, 1),
            'min_elevation' => round($minEle, 1),
            'max_elevation' => round($maxEle, 1),
            'max_speed' => round($maxSpeed, 3),
            'avg_gap_speed' => $gapCount > 0 ? round($gapSpeedSum / $gapCount, 3) : null,
            'avg_hr' => (int) round($hrSum / $sampleCount),
            'max_hr' => $maxHr,
            'avg_cadence' => $cadCount > 0 ? round($cadSum / $cadCount, 1) : null,
            'max_cadence' => $maxCad > 0 ? (float) $maxCad : null,
            'avg_stride_length' => $isRunning ? round($strideSum / $sampleCount, 3) : null,
            'avg_vertical_oscillation' => $isRunning ? round($voSum / $sampleCount, 2) : null,
            'avg_vertical_ratio' => $isRunning ? round($vrSum / $sampleCount, 2) : null,
            'avg_ground_contact_time' => $isRunning ? round($gctSum / $sampleCount, 1) : null,
            'avg_power' => $avgPower,
            'max_power' => $maxPower > 0 ? (float) $maxPower : null,
            'normalized_power' => $np,
            'tss' => $tss,
            'intensity_factor' => $if,
            'total_work_kj' => $totalWorkKj,
            'power_curve' => $powerCurve,
            'hr_zones' => $hrZones,
            'power_zones' => $powerZones,
            'grit' => $isMtb ? round(max(14.0, $gritTotal), 1) : null,
            'avg_flow' => $flowCount > 0 ? round($flowSum / $flowCount, 1) : null,
            'end_stamina' => (int) round($stamina),
            'avg_respiration' => round($respSum / $sampleCount, 1),
            'run_duration_seconds' => $runDur,
            'walk_duration_seconds' => $walkDur,
            'idle_duration_seconds' => $idleDur,
        ];
    }

    /**
     * Place realistic MTB jump events spaced out across steep downhill trail segments.
     *
     * @param  list<array{idx: int, grade: float, speed: float, time: float, lat: float, lng: float}>  $candidates
     * @param  list<array<string, mixed>>  $series
     * @return list<array{time: float, distance_meters: float, hang_time_seconds: float, score: float, speed: float, lat: float, lng: float}>
     */
    private function buildMtbJumps(array $candidates, int $desiredJumps, array $series): array
    {
        if ($desiredJumps <= 0 || $series === []) {
            return [];
        }

        if ($candidates === []) {
            // Fallback to mid-late section of trail if no steep sample met threshold.
            $count = count($series);
            $step = max(1, intdiv($count, $desiredJumps + 2));

            for ($k = 1; $k <= $desiredJumps; $k++) {
                $idx = min($count - 2, $k * $step);
                $pt = $series[$idx];
                $candidates[] = [
                    'idx' => $idx,
                    'grade' => -0.05,
                    'speed' => max(5.2, (float) ($pt['speed'] ?? 6.2)),
                    'time' => (float) ($pt['time'] ?? 0.0),
                    'lat' => (float) ($pt['lat'] ?? self::DEMO_CENTER_LAT),
                    'lng' => (float) ($pt['lng'] ?? self::DEMO_CENTER_LNG),
                ];
            }
        }

        $selected = [];
        $candCount = count($candidates);
        $stride = max(1, intdiv($candCount, $desiredJumps));

        for ($i = 0; $i < $desiredJumps; $i++) {
            $pickIdx = min($candCount - 1, ($i * $stride) + intdiv($stride, 2));
            $c = $candidates[$pickIdx];

            if ($selected !== [] && abs($c['time'] - $selected[count($selected) - 1]['time']) < 20.0) {
                continue;
            }

            $speed = round(max(5.4, min(9.8, $c['speed'] * 1.15)), 2);
            $hangTime = round($this->randFloat(0.28, 0.48), 2);
            $jumpDist = round($speed * $hangTime * $this->randFloat(0.95, 1.08), 2);
            $score = round($this->clamp(48.0 + ($jumpDist * 7.5) + ($hangTime * 22.0), 45.0, 92.0), 1);

            $selected[] = [
                'time' => round($c['time'], 1),
                'distance_meters' => $jumpDist,
                'hang_time_seconds' => $hangTime,
                'score' => $score,
                'speed' => $speed,
                'lat' => round($c['lat'], 7),
                'lng' => round($c['lng'], 7),
            ];
        }

        return $selected;
    }

    /**
     * Persist route-sampled ActivityWeather with genuine bearing-aligned headwind/crosswind/tailwind breakdown.
     *
     * @param  list<array<string, mixed>>  $series
     * @param  array<string, HourlyWeather>  $hourlyWeather
     */
    private function persistActivityWeather(
        Activity $activity,
        array $series,
        CarbonImmutable $startedLocal,
        array $hourlyWeather,
        bool $forceWindy,
    ): void {
        if ($series === []) {
            return;
        }

        $count = count($series);
        $waypointTarget = min(20, max(5, intdiv($count, 20)));
        $step = ($count - 1) / max(1, $waypointTarget - 1);

        $samples = [];
        $temps = [];
        $apparents = [];
        $humidities = [];
        $dewPoints = [];
        $windSpeeds = [];
        $windGusts = [];
        $precips = [];
        $clouds = [];
        $pressures = [];
        $uSum = 0.0;
        $vSum = 0.0;

        $headDist = 0.0;
        $crossDist = 0.0;
        $tailDist = 0.0;
        $effWindWeighted = 0.0;
        $totalBearingDist = 0.0;
        $worstWmo = 0;

        $prevLat = null;
        $prevLng = null;

        for ($w = 0; $w < $waypointTarget; $w++) {
            $idx = min($count - 1, (int) round($w * $step));
            $pt = $series[$idx];
            $tOffset = (float) ($pt['time'] ?? 0.0);
            $dOffset = (float) ($pt['distance'] ?? 0.0);
            $lat = (float) ($pt['lat'] ?? self::DEMO_CENTER_LAT);
            $lng = (float) ($pt['lng'] ?? self::DEMO_CENTER_LNG);

            $sampleTime = $startedLocal->addSeconds((int) round($tOffset));
            $wx = $this->resolveWeatherForTime($sampleTime, $hourlyWeather, $forceWindy);

            // Small spatial lapse adjustment for mountain elevation.
            $ele = (float) ($pt['altitude'] ?? 1650.0);
            $lapseDelta = ($ele - 1650.0) * -0.0065;
            $tempC = round($wx['temp'] + $lapseDelta, 1);
            $apparentC = round($wx['apparent'] + $lapseDelta, 1);
            $windSpeed = round($wx['wind_speed'] * (1.0 + max(0.0, ($ele - 1650.0) / 2500.0)), 1);
            $windDir = ($wx['wind_dir'] + $this->rng->getInt(-6, 6) + 360) % 360;

            $samples[] = [
                'time_offset_s' => (int) round($tOffset),
                'distance_m' => round($dOffset, 1),
                'lat' => round($lat, 6),
                'lng' => round($lng, 6),
                'wmo_code' => $wx['wmo'],
                'temperature_c' => $tempC,
                'apparent_temperature_c' => $apparentC,
                'humidity_pct' => $wx['humidity'],
                'dew_point_c' => round($wx['dew_point'] + ($lapseDelta * 0.6), 1),
                'wind_speed_kmh' => $windSpeed,
                'wind_gusts_kmh' => round(max($windSpeed * 1.25, $wx['wind_gusts']), 1),
                'wind_direction_deg' => $windDir,
                'wind_direction_compass' => $this->compassFromDegrees($windDir),
                'precipitation_mm' => $wx['precip'],
                'cloud_cover_pct' => $wx['cloud'],
                'surface_pressure_hpa' => $wx['pressure'],
            ];

            $temps[] = $tempC;
            $apparents[] = $apparentC;
            $humidities[] = $wx['humidity'];
            $dewPoints[] = $wx['dew_point'];
            $windSpeeds[] = $windSpeed;
            $windGusts[] = max($windSpeed * 1.25, $wx['wind_gusts']);
            $precips[] = $wx['precip'];
            $clouds[] = $wx['cloud'];
            $pressures[] = $wx['pressure'];
            $worstWmo = max($worstWmo, $wx['wmo']);

            $rad = deg2rad((float) $windDir);
            $uSum += $windSpeed * sin($rad);
            $vSum += $windSpeed * cos($rad);

            if ($prevLat !== null) {
                $segDist = RouteMatchingService::haversineMeters($prevLat, (float) $prevLng, $lat, $lng);

                if ($segDist > 5.0) {
                    $bearing = $this->bearingRadians($prevLat, (float) $prevLng, $lat, $lng);
                    $cosAngle = cos($bearing - $rad);
                    $effWind = $windSpeed * $cosAngle;
                    $effWindWeighted += $effWind * $segDist;
                    $totalBearingDist += $segDist;

                    if ($cosAngle >= 0.5) {
                        $headDist += $segDist;
                    } elseif ($cosAngle <= -0.5) {
                        $tailDist += $segDist;
                    } else {
                        $crossDist += $segDist;
                    }
                }
            }

            $prevLat = $lat;
            $prevLng = $lng;
        }

        $avgWind = round(array_sum($windSpeeds) / count($windSpeeds), 1);
        $avgDir = ((int) round(rad2deg(atan2($uSum, $vSum))) + 360) % 360;
        [$baseCondition, $baseIcon] = $this->describeWmoCode($worstWmo);
        $isWindy = $avgWind >= 19.0;

        ActivityWeather::query()->create([
            'activity_id' => $activity->id,
            'provider' => 'open_meteo',
            'model_endpoint' => 'archive',
            'sampling_mode' => 'route',
            'waypoint_count' => count($samples),
            'wmo_code' => $worstWmo,
            'condition' => $isWindy ? 'Windy' : $baseCondition,
            'base_condition' => $baseCondition,
            'icon' => $isWindy ? 'wind' : $baseIcon,
            'temperature_c' => round(array_sum($temps) / count($temps), 1),
            'min_temperature_c' => min($temps),
            'max_temperature_c' => max($temps),
            'apparent_temperature_c' => round(array_sum($apparents) / count($apparents), 1),
            'humidity_pct' => (int) round(array_sum($humidities) / count($humidities)),
            'dew_point_c' => round(array_sum($dewPoints) / count($dewPoints), 1),
            'wind_speed_kmh' => $avgWind,
            'wind_gusts_kmh' => round(max($windGusts), 1),
            'wind_direction_deg' => $avgDir,
            'wind_direction_compass' => $this->compassFromDegrees($avgDir),
            'precipitation_mm' => round(array_sum($precips), 2),
            'cloud_cover_pct' => (int) round(array_sum($clouds) / count($clouds)),
            'surface_pressure_hpa' => round(array_sum($pressures) / count($pressures), 1),
            'headwind_pct' => $totalBearingDist > 0.0 ? round(($headDist / $totalBearingDist) * 100.0, 1) : 33.3,
            'crosswind_pct' => $totalBearingDist > 0.0 ? round(($crossDist / $totalBearingDist) * 100.0, 1) : 33.4,
            'tailwind_pct' => $totalBearingDist > 0.0 ? round(($tailDist / $totalBearingDist) * 100.0, 1) : 33.3,
            'avg_effective_wind_kmh' => $totalBearingDist > 0.0 ? round($effWindWeighted / $totalBearingDist, 1) : 0.0,
            'samples' => $samples,
            'fetched_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    /**
     * Resolve hourly weather from the cached Open-Meteo archive or synthesize realistic Colorado seasonal weather.
     *
     * @param  array<string, HourlyWeather>  $hourlyWeather
     * @return HourlyWeather
     */
    private function resolveWeatherForTime(
        CarbonImmutable $localTime,
        array $hourlyWeather,
        bool $forceWindy,
    ): array {
        $key = $localTime->format('Y-m-d\TH:00');
        $wx = $hourlyWeather[$key] ?? null;

        if ($wx === null) {
            // Seasonal Colorado Front Range profile (cold crisp winters, warm sunny summers, afternoon Chinook winds).
            $doy = (int) $localTime->dayOfYear;
            $hour = (int) $localTime->hour;
            $seasonalMean = 12.0 - (12.5 * cos((2.0 * M_PI * ($doy - 18)) / 365.0));
            $diurnal = -5.5 * cos((2.0 * M_PI * ($hour - 4)) / 24.0);
            $temp = round($seasonalMean + $diurnal + $this->randGaussian(0.0, 2.8), 1);

            $windSpeed = round($this->clamp(11.5 + ($hour >= 13 ? 4.5 : 0.0) + abs($this->randGaussian(0.0, 5.5)), 4.0, 34.0), 1);
            $windDir = ($this->rng->getInt(245, 325)) % 360;
            $wmoRoll = $this->randFloat(0.0, 1.0);
            $wmo = match (true) {
                $wmoRoll < 0.42 => 0,
                $wmoRoll < 0.68 => 1,
                $wmoRoll < 0.84 => 2,
                $wmoRoll < 0.94 => 3,
                default => 61,
            };

            $wx = [
                'temp' => $temp,
                'apparent' => round($temp - ($windSpeed * 0.12), 1),
                'humidity' => (int) round($this->clamp(46.0 + ($wmo >= 3 ? 22.0 : 0.0) + $this->randGaussian(0.0, 10.0), 18.0, 88.0)),
                'dew_point' => round($temp - 9.5, 1),
                'precip' => $wmo >= 61 ? round($this->randFloat(0.2, 1.8), 2) : 0.0,
                'wmo' => $wmo,
                'cloud' => $wmo === 0 ? 8 : ($wmo === 1 ? 24 : ($wmo === 2 ? 52 : 85)),
                'pressure' => round(836.0 + $this->randGaussian(0.0, 4.5), 1),
                'wind_speed' => $windSpeed,
                'wind_dir' => $windDir,
                'wind_gusts' => round($windSpeed * 1.45, 1),
            ];
        }

        if ($forceWindy && $wx['wind_speed'] < 21.0) {
            $wx['wind_speed'] = round($this->randFloat(21.5, 25.5), 1);
            $wx['wind_gusts'] = round($wx['wind_speed'] * 1.42, 1);
            $wx['wind_dir'] = 282; // Classic WNW Front Range breeze across the century loop
        }

        return $wx;
    }

    /**
     * Geocode only the unique matched RouteClusters and detected Segments via Nominatim
     * (fast: ~25–35 unique items instead of hundreds of activities).
     */
    private function geocodeMatchedRoutesAndSegments(ReverseGeocodingService $geocoder): void
    {
        $clusters = RouteCluster::query()->matched()->orderByDesc('activity_count')->get();
        $segments = Segment::query()->orderByDesc('effort_count')->get();

        $total = $clusters->count() + $segments->count();
        $this->components->info("Geocoding {$clusters->count()} matched routes and {$segments->count()} detected climbs via Nominatim ({$total} lookups)...");

        $enriched = 0;

        foreach ($clusters as $cluster) {
            try {
                if ($geocoder->enrichRouteCluster($cluster)) {
                    $enriched++;
                }
            } catch (Throwable) {
                $this->components->warn('Nominatim rate-limited or unavailable; falling back to local Boulder place labels.');
                $this->applyOfflinePlaceLabels();

                return;
            }
        }

        foreach ($segments as $segment) {
            try {
                if ($geocoder->enrichSegment($segment)) {
                    $enriched++;
                }
            } catch (Throwable) {
                $this->components->warn('Nominatim rate-limited or unavailable; filling remaining segments with Boulder place labels.');
                $this->applyOfflinePlaceLabels();

                return;
            }
        }

        $this->components->twoColumnDetail('Geocoded Routes & Climbs', (string) $enriched);
    }

    private function applyOfflinePlaceLabels(): void
    {
        $now = CarbonImmutable::now('UTC');

        RouteCluster::query()
            ->whereNull('place_label')
            ->update([
                'place_label' => 'Boulder, Colorado',
                'geocoded_at' => $now,
                'geocode_attempted_at' => $now,
            ]);

        Segment::query()
            ->whereNull('place_label')
            ->get()
            ->each(function (Segment $segment) use ($now): void {
                $segment->update([
                    'place_label' => 'Boulder Foothills · Boulder',
                    'geocoded_at' => $now,
                    'geocode_attempted_at' => $now,
                ]);
            });
    }

    /**
     * @param  array<string, Activity>  $heroActivities
     */
    private function renderSummaryAndUrls(string $dbPath, array $heroActivities): void
    {
        $topClimb = Segment::query()
            ->where('kind', Segment::KIND_CLIMB)
            ->orderByDesc('effort_count')
            ->first();

        $this->components->twoColumnDetail('Database File', $dbPath);
        $this->components->twoColumnDetail('Activities', (string) Activity::query()->count());
        $this->components->twoColumnDetail('Activity Streams', (string) ActivityStream::query()->count());
        $this->components->twoColumnDetail('Matched Routes (2+)', (string) RouteCluster::query()->matched()->count());
        $this->components->twoColumnDetail('Detected Climbs', (string) Segment::query()->count());
        $this->components->twoColumnDetail('Daily Health Days', (string) DailyMetric::query()->count());

        $this->newLine();
        $this->components->info("Showcase URLs for CAPTURE_GUIDE.local.md (serve with: (cd public && DB_DATABASE={$dbPath} php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php)):");

        if (isset($heroActivities['hero_run'])) {
            $a = $heroActivities['hero_run'];
            $this->components->twoColumnDetail('Hero Run (Profile + Hover)', "/activities/{$a->id}  ({$a->name})");
        }

        if (isset($heroActivities['hero_road'])) {
            $a = $heroActivities['hero_road'];
            $this->components->twoColumnDetail('Hero Road Ride (Profile + Wind)', "/activities/{$a->id}  ({$a->name})");
        }

        if (isset($heroActivities['hero_mtb'])) {
            $a = $heroActivities['hero_mtb'];
            $heroStream = $a->stream;
            $jumps = $heroStream !== null ? ($heroStream->jumps ?? []) : [];
            $jumpHint = isset($jumps[0]) && is_array($jumps[0])
                ? sprintf('Jump #1 @ %.6f, %.6f', (float) ($jumps[0]['lat'] ?? 0), (float) ($jumps[0]['lng'] ?? 0))
                : 'no jumps';
            $this->components->twoColumnDetail('Hero MTB Ride (3D + Speed + Jump)', "/activities/{$a->id}  ({$a->name} — {$jumpHint})");
        }

        if ($topClimb !== null) {
            $this->components->twoColumnDetail(
                'Signature Climb Segment',
                "/segments/{$topClimb->id}  ({$topClimb->name} · {$topClimb->effort_count} efforts · {$topClimb->climb_category})",
            );
        }

        $this->components->twoColumnDetail('Heatmap 3D Framing (Boulder Foothills)', 'lng -105.29, lat 40.015 · zoom 12.2 · pitch 58 · bearing -35');
    }

    /**
     * @return RouteDefinition
     */
    private function findRouteDefinition(string $key): array
    {
        foreach (self::ROUTE_DEFINITIONS as $definition) {
            if ($definition['key'] === $key) {
                return $definition;
            }
        }

        throw new RuntimeException("Unknown route definition key: {$key}");
    }

    /**
     * Add smooth low-amplitude correlated GPS jitter (~2–3 m) so repeated activities on the same route
     * look like distinct real GNSS tracks without leaving the road or breaking route matching.
     *
     * @param  list<RoutePoint>  $points
     * @return list<RoutePoint>
     */
    private function jitterRoutePoints(array $points): array
    {
        $latPhase = $this->randFloat(0.0, 6.28);
        $lngPhase = $this->randFloat(0.0, 6.28);
        $eleBias = $this->randFloat(-1.8, 1.8);

        $jittered = [];

        foreach ($points as $i => $pt) {
            // ~0.000022 deg ≈ 2.4 meters
            $dLat = sin(($i * 0.14) + $latPhase) * 0.000022;
            $dLng = cos(($i * 0.11) + $lngPhase) * 0.000026;

            $jittered[] = [
                'lat' => $pt['lat'] + $dLat,
                'lng' => $pt['lng'] + $dLng,
                'ele' => round($pt['ele'] + $eleBias + (sin($i * 0.08) * 0.6), 1),
                'dist' => $pt['dist'],
            ];
        }

        return $jittered;
    }

    /**
     * @param  list<RoutePoint>  $points
     * @return array{idx: int, lat: float, lng: float, ele: float, dist: float}
     */
    private function interpolateRouteAtDistance(array $points, float $targetDist, int $hintIdx): array
    {
        $count = count($points);
        $idx = max(0, min($count - 2, $hintIdx));

        while ($idx < $count - 2 && $points[$idx + 1]['dist'] < $targetDist) {
            $idx++;
        }

        $p0 = $points[$idx];
        $p1 = $points[$idx + 1];
        $span = $p1['dist'] - $p0['dist'];
        $frac = $span > 0.0 ? $this->clamp(($targetDist - $p0['dist']) / $span, 0.0, 1.0) : 0.0;

        return [
            'idx' => $idx,
            'lat' => $p0['lat'] + (($p1['lat'] - $p0['lat']) * $frac),
            'lng' => $p0['lng'] + (($p1['lng'] - $p0['lng']) * $frac),
            'ele' => $p0['ele'] + (($p1['ele'] - $p0['ele']) * $frac),
            'dist' => $p0['dist'] + ($span * $frac),
        ];
    }

    /**
     * Solve Martin et al. road cycling power balance for steady-state speed (m/s) via Newton-Raphson.
     */
    private function solveCyclingSpeed(
        float $powerWatts,
        float $grade,
        float $headwindMs,
        float $massKg,
        float $crr,
        float $cda,
    ): float {
        $g = 9.80665;
        $rho = 1.04; // Boulder ~1,650m altitude air density (kg/m^3)
        $drivetrainEff = 0.97;
        $pWheel = max(0.0, $powerWatts * $drivetrainEff);

        $slopeAngle = atan($grade);
        $fGravRoll = $massKg * $g * (sin($slopeAngle) + ($crr * cos($slopeAngle)));

        $v = $grade < -0.02 ? 12.0 : ($grade > 0.04 ? 4.5 : 8.5);

        for ($iter = 0; $iter < 10; $iter++) {
            $vAir = $v + $headwindMs;
            $fAero = 0.5 * $rho * $cda * $vAir * abs($vAir);
            $fTotal = $fGravRoll + $fAero;
            $fx = ($fTotal * $v) - $pWheel;
            $dfx = $fTotal + ($v * $rho * $cda * abs($vAir));

            if (abs($dfx) < 1e-4) {
                break;
            }

            $vNext = $v - ($fx / $dfx);
            $v = $this->clamp($vNext, 1.4, 21.0);

            if (abs($fx) < 0.5) {
                break;
            }
        }

        return $v;
    }

    private function bearingRadians(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dLambda = deg2rad($lng2 - $lng1);

        $y = sin($dLambda) * cos($phi2);
        $x = (cos($phi1) * sin($phi2)) - (sin($phi1) * cos($phi2) * cos($dLambda));

        return atan2($y, $x);
    }

    /**
     * @param  list<float>  $powerSamples
     */
    private function computeNormalizedPower(array $powerSamples, int $stepSec): ?float
    {
        $count = count($powerSamples);

        if ($count < 4) {
            return null;
        }

        $window = max(1, (int) round(30.0 / max(1, $stepSec)));
        $sumFourth = 0.0;
        $windows = 0;
        $rollingSum = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $rollingSum += $powerSamples[$i];

            if ($i >= $window) {
                $rollingSum -= $powerSamples[$i - $window];
            }

            if ($i >= $window - 1) {
                $avg = $rollingSum / $window;
                $sumFourth += ($avg * $avg * $avg * $avg);
                $windows++;
            }
        }

        if ($windows === 0) {
            return null;
        }

        return round(pow($sumFourth / $windows, 0.25), 1);
    }

    /**
     * Compute Mean Maximal Power curve across standard cycling durations (1s to 7200s).
     *
     * @param  list<float>  $powerSamples
     * @return array<int, int>
     */
    private function computePowerCurve(array $powerSamples, int $stepSec, int $maxPower): array
    {
        $count = count($powerSamples);

        if ($count === 0) {
            return [];
        }

        $prefix = [0.0];

        foreach ($powerSamples as $p) {
            $prefix[] = $prefix[count($prefix) - 1] + $p;
        }

        $totalSec = $count * $stepSec;
        $curve = [];
        $prevBest = null;

        foreach (self::POWER_CURVE_DURATIONS as $dur) {
            if ($dur > $totalSec) {
                continue;
            }

            if ($dur < $stepSec) {
                // Short sprint peaks (1s–5s) naturally exceed a 5–10s smoothed sample peak.
                $sprintScale = match ($dur) {
                    1 => 1.78,
                    2 => 1.66,
                    5 => 1.48,
                    default => 1.25,
                };
                $best = (int) round(min(1080.0, $maxPower * $sprintScale));
            } else {
                $winSamples = max(1, (int) round($dur / $stepSec));

                if ($winSamples > $count) {
                    continue;
                }

                $maxAvg = 0.0;

                for ($i = $winSamples; $i <= $count; $i++) {
                    $avg = ($prefix[$i] - $prefix[$i - $winSamples]) / $winSamples;

                    if ($avg > $maxAvg) {
                        $maxAvg = $avg;
                    }
                }

                $best = (int) round($maxAvg);
            }

            if ($prevBest !== null && $best > $prevBest) {
                $best = $prevBest;
            }

            $curve[$dur] = $best;
            $prevBest = $best;
        }

        return $curve;
    }

    /**
     * @param  list<array<string, mixed>>  $series
     * @return list<array<string, mixed>>
     */
    private function computeKmSplits(array $series): array
    {
        if (count($series) < 2) {
            return [];
        }

        $splits = [];
        $kmIndex = 1;
        $bucketStartIdx = 0;

        for ($i = 1; $i < count($series); $i++) {
            $dist = (float) ($series[$i]['distance'] ?? 0.0);

            if ($dist >= ($kmIndex * 1000.0) || $i === count($series) - 1) {
                $startPt = $series[$bucketStartIdx];
                $endPt = $series[$i];
                $dDist = (float) ($endPt['distance'] ?? 0.0) - (float) ($startPt['distance'] ?? 0.0);
                $dTime = (float) ($endPt['time'] ?? 0.0) - (float) ($startPt['time'] ?? 0.0);

                if ($dDist >= 150.0 && $dTime > 0.0) {
                    $slice = array_slice($series, $bucketStartIdx, $i - $bucketStartIdx + 1);
                    $avgSpeed = $dDist / $dTime;
                    $stats = $this->summarizeSeriesSlice($slice);

                    $splits[] = [
                        'km' => $kmIndex,
                        'distance_meters' => round($dDist, 1),
                        'duration_seconds' => round($dTime, 2),
                        'avg_speed' => round($avgSpeed, 3),
                        'pace_seconds_per_km' => round(1000.0 / max(0.2, $avgSpeed), 2),
                        'gap_seconds_per_km' => $stats['avg_gap_speed'] !== null ? round(1000.0 / max(0.2, $stats['avg_gap_speed']), 2) : null,
                        'avg_hr' => $stats['avg_hr'],
                        'avg_cadence' => $stats['avg_cadence'],
                        'avg_power' => $stats['avg_power'],
                        'elevation_gain' => $stats['elevation_gain'],
                        'elevation_loss' => $stats['elevation_loss'],
                        'avg_grade' => round((($stats['net_ele']) / max(1.0, $dDist)) * 100.0, 2),
                    ];
                }

                $bucketStartIdx = $i;
                $kmIndex++;
            }
        }

        return $splits;
    }

    /**
     * @param  list<array<string, mixed>>  $series
     * @return list<array<string, mixed>>
     */
    private function computeLaps(array $series, float $lapMeters): array
    {
        if (count($series) < 2) {
            return [];
        }

        $laps = [];
        $lapNumber = 1;
        $bucketStartIdx = 0;

        for ($i = 1; $i < count($series); $i++) {
            $dist = (float) ($series[$i]['distance'] ?? 0.0);

            if ($dist >= ($lapNumber * $lapMeters) || $i === count($series) - 1) {
                $startPt = $series[$bucketStartIdx];
                $endPt = $series[$i];
                $dDist = (float) ($endPt['distance'] ?? 0.0) - (float) ($startPt['distance'] ?? 0.0);
                $dTime = (float) ($endPt['time'] ?? 0.0) - (float) ($startPt['time'] ?? 0.0);

                if ($dDist >= 150.0 && $dTime > 0.0) {
                    $slice = array_slice($series, $bucketStartIdx, $i - $bucketStartIdx + 1);
                    $stats = $this->summarizeSeriesSlice($slice);

                    $laps[] = [
                        'lap' => $lapNumber,
                        'distance_meters' => round($dDist, 1),
                        'duration_seconds' => round($dTime, 2),
                        'elapsed_seconds' => round($dTime * 1.02, 2),
                        'avg_speed' => round($dDist / $dTime, 3),
                        'gap_seconds_per_km' => $stats['avg_gap_speed'] !== null ? round(1000.0 / max(0.2, $stats['avg_gap_speed']), 2) : null,
                        'avg_hr' => $stats['avg_hr'],
                        'max_hr' => $stats['max_hr'],
                        'avg_cadence' => $stats['avg_cadence'],
                        'avg_power' => $stats['avg_power'],
                        'elevation_gain' => $stats['elevation_gain'],
                        'elevation_loss' => $stats['elevation_loss'],
                    ];
                }

                $bucketStartIdx = $i;
                $lapNumber++;
            }
        }

        return $laps;
    }

    /**
     * @param  list<array<string, mixed>>  $slice
     * @return array{
     *     avg_hr: ?int,
     *     max_hr: ?int,
     *     avg_cadence: ?int,
     *     avg_power: ?int,
     *     avg_gap_speed: ?float,
     *     elevation_gain: float,
     *     elevation_loss: float,
     *     net_ele: float
     * }
     */
    private function summarizeSeriesSlice(array $slice): array
    {
        $hrSum = 0.0;
        $hrCount = 0;
        $maxHr = 0;
        $cadSum = 0.0;
        $cadCount = 0;
        $powSum = 0.0;
        $powCount = 0;
        $gapSum = 0.0;
        $gapCount = 0;
        $gain = 0.0;
        $loss = 0.0;
        $prevEle = null;
        $firstEle = null;
        $lastEle = null;

        foreach ($slice as $pt) {
            if (is_numeric($pt['hr'] ?? null)) {
                $h = (int) $pt['hr'];
                $hrSum += $h;
                $hrCount++;
                $maxHr = max($maxHr, $h);
            }

            if (is_numeric($pt['cadence'] ?? null) && (float) $pt['cadence'] > 0) {
                $cadSum += (float) $pt['cadence'];
                $cadCount++;
            }

            if (is_numeric($pt['power'] ?? null)) {
                $powSum += (float) $pt['power'];
                $powCount++;
            }

            if (is_numeric($pt['gap_speed'] ?? null)) {
                $gapSum += (float) $pt['gap_speed'];
                $gapCount++;
            }

            if (is_numeric($pt['altitude'] ?? null)) {
                $e = (float) $pt['altitude'];
                $firstEle ??= $e;
                $lastEle = $e;

                if ($prevEle !== null) {
                    $d = $e - $prevEle;

                    if ($d > 0.2) {
                        $gain += $d;
                    } elseif ($d < -0.2) {
                        $loss += abs($d);
                    }
                }

                $prevEle = $e;
            }
        }

        return [
            'avg_hr' => $hrCount > 0 ? (int) round($hrSum / $hrCount) : null,
            'max_hr' => $maxHr > 0 ? $maxHr : null,
            'avg_cadence' => $cadCount > 0 ? (int) round($cadSum / $cadCount) : null,
            'avg_power' => $powCount > 0 ? (int) round($powSum / $powCount) : null,
            'avg_gap_speed' => $gapCount > 0 ? ($gapSum / $gapCount) : null,
            'elevation_gain' => round($gain, 1),
            'elevation_loss' => round($loss, 1),
            'net_ele' => ($firstEle !== null && $lastEle !== null) ? ($lastEle - $firstEle) : 0.0,
        ];
    }

    private function classifyHrZone(int $hr): int
    {
        return match (true) {
            $hr >= 176 => 5,
            $hr >= 163 => 4,
            $hr >= 149 => 3,
            $hr >= 134 => 2,
            default => 1,
        };
    }

    private function classifyPowerZone(float $watts, float $ftp): int
    {
        $ratio = $watts / max(100.0, $ftp);

        return match (true) {
            $ratio >= 1.50 => 7,
            $ratio >= 1.20 => 6,
            $ratio >= 1.05 => 5,
            $ratio >= 0.90 => 4,
            $ratio >= 0.75 => 3,
            $ratio >= 0.55 => 2,
            default => 1,
        };
    }

    private function compassFromDegrees(int $deg): string
    {
        $dirs = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];

        return $dirs[((int) round($deg / 22.5)) % 16];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function describeWmoCode(int $wmo): array
    {
        return match (true) {
            $wmo === 0 => ['Clear', 'sun'],
            $wmo === 1 => ['Mostly Clear', 'sun'],
            $wmo === 2 => ['Partly Cloudy', 'cloud-sun'],
            $wmo === 3 => ['Overcast', 'cloud'],
            $wmo >= 45 && $wmo <= 48 => ['Foggy', 'cloud-fog'],
            $wmo >= 51 && $wmo <= 57 => ['Drizzle', 'cloud-drizzle'],
            $wmo >= 61 && $wmo <= 67 => ['Light Rain', 'cloud-rain'],
            $wmo >= 80 && $wmo <= 82 => ['Rain Showers', 'cloud-rain'],
            default => ['Partly Cloudy', 'cloud-sun'],
        };
    }

    private function randFloat(float $min, float $max): float
    {
        return $min + (($this->rng->getInt(0, 1_000_000) / 1_000_000.0) * ($max - $min));
    }

    private function randGaussian(float $mean, float $stdDev): float
    {
        $u1 = max(1e-7, $this->randFloat(0.0, 1.0));
        $u2 = $this->randFloat(0.0, 1.0);
        $z = sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);

        return $mean + ($z * $stdDev);
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
