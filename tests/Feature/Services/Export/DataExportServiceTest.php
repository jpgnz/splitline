<?php

use App\Models\Activity;
use App\Models\ActivityDerivedMetric;
use App\Models\ActivityStream;
use App\Models\ActivityWeather;
use App\Services\Export\DataExportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-26 09:30:15', 'Pacific/Auckland'));
});

it('creates a valid compacted sqlite backup snapshot from a file-backed sqlite database', function () {
    $tempDbPath = '/tmp/opencode/splitline-test-source-'.uniqid('', true).'.sqlite';
    @mkdir(dirname($tempDbPath), 0777, true);
    touch($tempDbPath);

    $originalDefault = config('database.default');
    $snapshotPath = null;

    try {
        config([
            'database.connections.sqlite_backup_test' => [
                'driver' => 'sqlite',
                'database' => $tempDbPath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => 'sqlite_backup_test',
        ]);

        DB::connection('sqlite_backup_test')->statement('PRAGMA journal_mode = WAL');
        DB::connection('sqlite_backup_test')->statement('CREATE TABLE export_probe (id INTEGER PRIMARY KEY, label TEXT NOT NULL)');
        DB::connection('sqlite_backup_test')->insert('INSERT INTO export_probe (label) VALUES (?)', ['Morning Long Run']);

        $service = app(DataExportService::class);
        $snapshot = $service->createSqliteBackupSnapshot();
        $snapshotPath = $snapshot['path'];

        expect($snapshot['filename'])->toBe('splitline-backup-2026-11-26-093015.sqlite')
            ->and($snapshot['size_bytes'])->toBeGreaterThan(0)
            ->and(is_file($snapshotPath))->toBeTrue();

        $pdo = new PDO('sqlite:'.$snapshotPath);
        $rows = $pdo->query('SELECT label FROM export_probe')->fetchAll(PDO::FETCH_COLUMN);

        expect($rows)->toBe(['Morning Long Run']);
    } finally {
        config(['database.default' => $originalDefault]);
        DB::purge('sqlite_backup_test');

        foreach ([$tempDbPath, $tempDbPath.'-wal', $tempDbPath.'-shm', $snapshotPath] as $path) {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }
    }
});

it('throws when attempting a live sqlite backup on an in-memory sqlite connection', function () {
    $service = app(DataExportService::class);

    expect(fn () => $service->createSqliteBackupSnapshot())
        ->toThrow(RuntimeException::class, 'in-memory');
});

it('throws when attempting a live sqlite backup on a non-sqlite connection', function () {
    $originalDefault = config('database.default');

    try {
        config([
            'database.connections.non_sqlite_test' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'database' => 'splitline_test',
                'username' => 'root',
                'password' => '',
            ],
            'database.default' => 'non_sqlite_test',
        ]);

        $service = app(DataExportService::class);

        expect(fn () => $service->createSqliteBackupSnapshot())
            ->toThrow(RuntimeException::class, 'only supported for SQLite');
    } finally {
        config(['database.default' => $originalDefault]);
        DB::purge('non_sqlite_test');
    }
});

it('streams filtered csv exports with derived metrics, weather, and formula injection protection', function () {
    $matching = Activity::factory()->create([
        'name' => '=SUM(A1:A2) Tempo Run',
        'location_name' => '+Auckland Waterfront',
        'sport_category' => 'running',
        'sport_discipline' => 'road',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 06:30:00'),
        'started_at_utc' => CarbonImmutable::parse('2026-11-19 17:30:00', 'UTC'),
        'distance_meters' => 10000.0,
        'duration_seconds' => 2400.0,
        'is_favorite' => true,
        'is_pr' => true,
    ]);

    ActivityDerivedMetric::factory()->create([
        'activity_id' => $matching->id,
        'decoupling_pct' => -2.4,
        'efficiency_factor' => 1.62,
        'pacing_strategy' => 'negative_split',
    ]);

    ActivityWeather::factory()->create([
        'activity_id' => $matching->id,
        'condition' => 'Clear',
        'temperature_c' => -1.5,
        'wind_speed_kmh' => 14.2,
        'headwind_pct' => 45.0,
    ]);

    Activity::factory()->mountainBiking()->create([
        'name' => 'Woodhill Trail Ride',
        'started_at_local' => CarbonImmutable::parse('2026-11-21 08:00:00'),
        'is_favorite' => true,
        'is_pr' => true,
    ]);

    Activity::factory()->create([
        'name' => 'Older Easy Run',
        'started_at_local' => CarbonImmutable::parse('2026-09-01 07:00:00'),
        'is_favorite' => false,
        'is_pr' => false,
    ]);

    $service = app(DataExportService::class);
    $response = $service->streamActivitiesCsv([
        'sport' => 'running',
        'search' => 'tempo',
        'from' => '2026-11-01',
        'to' => '2026-11-26',
        'favorite' => true,
        'pr' => true,
    ]);

    ob_start();
    $response->sendContent();
    $csvContent = (string) ob_get_clean();

    $lines = array_values(array_filter(explode("\n", trim($csvContent)), static fn (string $line): bool => $line !== ''));
    $header = str_getcsv($lines[0], ',', '"', '');
    $row = str_getcsv($lines[1], ',', '"', '');
    $mapped = array_combine($header, $row);

    expect($lines)->toHaveCount(2)
        ->and($header)->toBe(DataExportService::EXPORT_COLUMNS)
        ->and($mapped['id'])->toBe((string) $matching->id)
        ->and($mapped['name'])->toBe("'=SUM(A1:A2) Tempo Run")
        ->and($mapped['location_name'])->toBe("'+Auckland Waterfront")
        ->and($mapped['effective_discipline'])->toBe('road')
        ->and($mapped['decoupling_pct'])->toBe('-2.4')
        ->and($mapped['efficiency_factor'])->toBe('1.62')
        ->and($mapped['pacing_strategy'])->toBe('negative_split')
        ->and($mapped['weather_condition'])->toBe('Clear')
        ->and($mapped['temperature_c'])->toBe('-1.5')
        ->and($mapped['wind_speed_kmh'])->toBe('14.2')
        ->and($mapped['headwind_pct'])->toBe('45');
});

it('sanitizes formula-triggering strings while preserving pure negative numbers', function () {
    $service = app(DataExportService::class);

    expect($service->sanitizeCsvCell('=1+1'))->toBe("'=1+1")
        ->and($service->sanitizeCsvCell('+cmd'))->toBe("'+cmd")
        ->and($service->sanitizeCsvCell('-cmd'))->toBe("'-cmd")
        ->and($service->sanitizeCsvCell('@SUM(A1)'))->toBe("'@SUM(A1)")
        ->and($service->sanitizeCsvCell("\t1+1"))->toBe("'\t1+1")
        ->and($service->sanitizeCsvCell("\r1+1"))->toBe("'\r1+1")
        ->and($service->sanitizeCsvCell('-4.25'))->toBe('-4.25')
        ->and($service->sanitizeCsvCell(-12.5))->toBe('-12.5')
        ->and($service->sanitizeCsvCell('Normal Run'))->toBe('Normal Run');
});

it('streams filtered json exports with exact structure', function () {
    $activity = Activity::factory()->roadBiking()->create([
        'name' => 'Tamaki Drive Intervals',
        'started_at_local' => CarbonImmutable::parse('2026-11-22 07:00:00'),
        'started_at_utc' => CarbonImmutable::parse('2026-11-21 18:00:00', 'UTC'),
        'is_favorite' => true,
    ]);

    ActivityDerivedMetric::factory()->create([
        'activity_id' => $activity->id,
        'decoupling_pct' => 1.8,
        'efficiency_factor' => 1.75,
        'pacing_strategy' => 'even_split',
    ]);

    Activity::factory()->create([
        'name' => 'Excluded Run',
        'sport_category' => 'running',
        'is_favorite' => false,
    ]);

    $service = app(DataExportService::class);
    $response = $service->streamActivitiesJson([
        'sport' => 'road_cycling',
        'favorite' => '1',
    ]);

    ob_start();
    $response->sendContent();
    $decoded = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toHaveCount(1)
        ->and(array_keys($decoded[0]))->toBe(DataExportService::EXPORT_COLUMNS)
        ->and($decoded[0]['name'])->toBe('Tamaki Drive Intervals')
        ->and($decoded[0]['effective_discipline'])->toBe('road')
        ->and($decoded[0]['is_favorite'])->toBeTrue()
        ->and($decoded[0]['decoupling_pct'])->toBe(1.8)
        ->and($decoded[0]['weather_condition'])->toBeNull();
});

it('builds valid gpx 1.1 xml with telemetry extensions from stream series', function () {
    $activity = Activity::factory()->create([
        'name' => 'Hills & Loops <Morning>',
        'activity_type_key' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 06:30:00'),
        'started_at_utc' => CarbonImmutable::parse('2026-11-19 17:30:00', 'UTC'),
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => [
            [
                'time' => 0.0,
                'lat' => -36.8485,
                'lng' => 174.7633,
                'altitude' => 15.2,
                'hr' => 142,
                'cadence' => 174,
                'power' => 285,
            ],
            [
                'time' => 60.0,
                'lat' => -36.8470,
                'lng' => 174.7650,
                'altitude' => 21.8,
                'hr' => 156,
                'cadence' => 178,
                'power' => 310,
            ],
        ],
        'polyline' => [],
    ]);

    $service = app(DataExportService::class);
    $result = $service->buildActivityGpx($activity);

    expect($result)->not->toBeNull()
        ->and($result['filename'])->toBe('2026-11-20-hills-loops-morning.gpx');

    $xml = new SimpleXMLElement($result['xml']);
    $namespaces = $xml->getNamespaces(true);
    $trackPoints = $xml->trk->trkseg->trkpt;

    expect((string) $xml['version'])->toBe('1.1')
        ->and((string) $xml['creator'])->toBe('Splitline')
        ->and((string) $xml->metadata->name)->toBe('Hills & Loops <Morning>')
        ->and((string) $xml->metadata->time)->toBe('2026-11-19T17:30:00Z')
        ->and((string) $xml->trk->type)->toBe('running')
        ->and(count($trackPoints))->toBe(2)
        ->and((string) $trackPoints[0]['lat'])->toBe('-36.848500')
        ->and((string) $trackPoints[0]['lon'])->toBe('174.763300')
        ->and((string) $trackPoints[0]->ele)->toBe('15.2')
        ->and((string) $trackPoints[0]->time)->toBe('2026-11-19T17:30:00Z')
        ->and((string) $trackPoints[1]->time)->toBe('2026-11-19T17:31:00Z')
        ->and((string) $trackPoints[0]->extensions->power)->toBe('285');

    $gpxtpx = $trackPoints[0]->extensions->children($namespaces['gpxtpx']);
    expect((string) $gpxtpx->TrackPointExtension->hr)->toBe('142')
        ->and((string) $gpxtpx->TrackPointExtension->cad)->toBe('174');
});

it('falls back to stream polyline when series has no gps coordinates', function () {
    $activity = Activity::factory()->create([
        'name' => 'Waterfront Jog',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 06:30:00'),
        'started_at_utc' => CarbonImmutable::parse('2026-11-19 17:30:00', 'UTC'),
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => [
            ['time' => 0.0, 'hr' => 140],
            ['time' => 60.0, 'hr' => 148],
        ],
        'polyline' => [
            [-36.8485, 174.7633],
            [-36.8470, 174.7650],
        ],
    ]);

    $service = app(DataExportService::class);
    $result = $service->buildActivityGpx($activity);

    expect($result)->not->toBeNull();

    $xml = new SimpleXMLElement($result['xml']);

    expect(count($xml->trk->trkseg->trkpt))->toBe(2)
        ->and((string) $xml->trk->trkseg->trkpt[1]['lat'])->toBe('-36.847000')
        ->and((string) $xml->trk->trkseg->trkpt[1]['lon'])->toBe('174.765000');
});

it('returns null gpx for indoor activities without gps coordinates', function () {
    $activity = Activity::factory()->indoor()->create();

    ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => [
            ['time' => 0.0, 'hr' => 135, 'power' => 200],
        ],
        'polyline' => [],
    ]);

    $service = app(DataExportService::class);

    expect($service->buildActivityGpx($activity))->toBeNull();
});

it('resolves cached original archives and summarizes storage inventory', function () {
    Storage::fake('local');

    $garminActivity = Activity::factory()->create([
        'provider' => 'garmin_personal',
        'garmin_activity_id' => 99887766,
        'external_id' => '99887766',
        'name' => 'Domain Loops',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
    ]);

    $fitUploadActivity = Activity::factory()->create([
        'provider' => 'fit_upload',
        'garmin_activity_id' => null,
        'external_id' => 'upload-abc123',
        'name' => 'Trainer Session',
        'started_at_local' => CarbonImmutable::parse('2026-11-21 07:00:00'),
    ]);

    Storage::disk('local')->put('garmin/fits/99887766.zip', "PK\x03\x04zipbytes");
    Storage::disk('local')->put('fit_upload/fits/upload-abc123.fit', '.FITBINARYDATA');
    Storage::disk('local')->put('garmin/fits/.gitkeep', '');

    $service = app(DataExportService::class);
    $garminArchive = $service->resolveOriginalArchive($garminActivity);
    $uploadArchive = $service->resolveOriginalArchive($fitUploadActivity);
    $summary = $service->summaryPayload();

    expect($garminArchive)->toBe([
        'disk_path' => 'garmin/fits/99887766.zip',
        'filename' => '2026-11-20-domain-loops.zip',
        'content_type' => 'application/zip',
    ]);

    expect($uploadArchive)->toBe([
        'disk_path' => 'fit_upload/fits/upload-abc123.fit',
        'filename' => '2026-11-21-trainer-session.fit',
        'content_type' => 'application/octet-stream',
    ]);

    expect($summary)->toBe([
        'is_sqlite' => false,
        'database_size_bytes' => null,
        'activities_count' => 2,
        'cached_archives_count' => 2,
        'cached_archives_size_bytes' => strlen("PK\x03\x04zipbytes") + strlen('.FITBINARYDATA'),
    ]);
});
