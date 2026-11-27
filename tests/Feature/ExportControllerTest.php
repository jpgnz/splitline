<?php

use App\Models\Activity;
use App\Models\ActivityDerivedMetric;
use App\Models\ActivityStream;
use App\Models\ActivityWeather;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-26 11:00:00', 'Pacific/Auckland'));
});

it('downloads a live sqlite backup snapshot when using a file-backed sqlite database', function () {
    $tempDbPath = sys_get_temp_dir().'/splitline-controller-backup-'.uniqid('', true).'.sqlite';
    touch($tempDbPath);

    $originalDefault = config('database.default');
    $downloadedTempCopy = sys_get_temp_dir().'/splitline-downloaded-'.uniqid('', true).'.sqlite';
    $snapshotPath = null;

    try {
        config([
            'database.connections.sqlite_controller_backup' => [
                'driver' => 'sqlite',
                'database' => $tempDbPath,
                'prefix' => '',
            ],
            'database.default' => 'sqlite_controller_backup',
        ]);

        DB::connection('sqlite_controller_backup')->statement('CREATE TABLE backup_check (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
        DB::connection('sqlite_controller_backup')->insert('INSERT INTO backup_check (title) VALUES (?)', ['Portable Backup OK']);

        $response = $this->get(route('exports.backup'));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/x-sqlite3');

        expect((string) $response->headers->get('Content-Disposition'))
            ->toContain('attachment')
            ->toContain('splitline-backup-2026-11-26-110000.sqlite');

        $baseResponse = $response->baseResponse;
        expect($baseResponse)->toBeInstanceOf(BinaryFileResponse::class);

        $snapshotPath = $baseResponse->getFile()->getPathname();
        copy($snapshotPath, $downloadedTempCopy);

        $pdo = new PDO('sqlite:'.$downloadedTempCopy);
        $titles = $pdo->query('SELECT title FROM backup_check')->fetchAll(PDO::FETCH_COLUMN);

        expect($titles)->toBe(['Portable Backup OK']);

        ob_start();
        $baseResponse->sendContent();
        ob_end_clean();

        expect(file_exists($snapshotPath))->toBeFalse();
    } finally {
        config(['database.default' => $originalDefault]);
        DB::purge('sqlite_controller_backup');

        foreach ([$tempDbPath, $tempDbPath.'-wal', $tempDbPath.'-shm', $downloadedTempCopy, $snapshotPath] as $path) {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }
    }
});

it('returns 422 when requesting a live backup on an in-memory sqlite database', function () {
    $this->get(route('exports.backup'))
        ->assertUnprocessable()
        ->assertJsonStructure(['message']);
});

it('rejects cross-site backup requests', function () {
    $this->withHeader('Sec-Fetch-Site', 'cross-site')
        ->get(route('exports.backup'))
        ->assertForbidden();

    $this->withHeader('Sec-Fetch-Site', 'same-origin')
        ->get(route('exports.backup'))
        ->assertUnprocessable();
});

it('throttles repeated backup requests', function () {
    foreach (range(1, 6) as $attempt) {
        $this->get(route('exports.backup'))->assertUnprocessable();
    }

    $this->get(route('exports.backup'))->assertTooManyRequests();
});

it('downloads filtered activities as csv by default', function () {
    $run = Activity::factory()->create([
        'name' => 'Mission Bay 10K',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 06:45:00'),
        'is_favorite' => true,
    ]);

    ActivityDerivedMetric::factory()->create([
        'activity_id' => $run->id,
        'decoupling_pct' => 2.1,
    ]);

    ActivityWeather::factory()->create([
        'activity_id' => $run->id,
        'condition' => 'Sunny',
    ]);

    Activity::factory()->mountainBiking()->create([
        'name' => 'Excluded MTB Ride',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 09:00:00'),
        'is_favorite' => false,
    ]);

    $response = $this->get(route('exports.activities', [
        'sport' => 'running',
        'favorite' => '1',
    ]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    expect((string) $response->headers->get('Content-Disposition'))
        ->toContain('attachment; filename="splitline-activities-2026-11-26.csv"');

    $csv = $response->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv)), static fn (string $line): bool => $line !== ''));

    expect($lines)->toHaveCount(2)
        ->and($lines[1])->toContain('Mission Bay 10K')
        ->and($lines[1])->toContain('Sunny')
        ->and($csv)->not->toContain('Excluded MTB Ride');
});

it('downloads filtered activities as json when format=json is requested', function () {
    Activity::factory()->create([
        'name' => 'Waitakere Trail Run',
        'sport_category' => 'running',
        'sport_discipline' => 'trail',
        'started_at_local' => CarbonImmutable::parse('2026-11-18 07:30:00'),
    ]);

    Activity::factory()->create([
        'name' => 'Road Tempo',
        'sport_category' => 'running',
        'sport_discipline' => 'road',
        'started_at_local' => CarbonImmutable::parse('2026-11-19 07:30:00'),
    ]);

    $response = $this->get(route('exports.activities', [
        'format' => 'json',
        'search' => 'waitakere',
    ]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/json; charset=UTF-8');

    expect((string) $response->headers->get('Content-Disposition'))
        ->toContain('attachment; filename="splitline-activities-2026-11-26.json"');

    $payload = json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toHaveCount(1)
        ->and($payload[0]['name'])->toBe('Waitakere Trail Run')
        ->and($payload[0]['effective_discipline'])->toBe('trail');
});

it('validates the export format parameter', function () {
    $this->getJson(route('exports.activities', ['format' => 'xml']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['format']);
});

it('downloads valid gpx 1.1 route xml for an activity with gps coordinates', function () {
    $activity = Activity::factory()->create([
        'name' => 'Cornwall Park 5K',
        'started_at_local' => CarbonImmutable::parse('2026-11-21 08:00:00'),
        'started_at_utc' => CarbonImmutable::parse('2026-11-20 19:00:00', 'UTC'),
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => [
            ['time' => 0, 'lat' => -36.8742, 'lng' => 174.7832, 'altitude' => 84.5, 'hr' => 150],
            ['time' => 30, 'lat' => -36.8750, 'lng' => 174.7841, 'altitude' => 88.0, 'hr' => 161],
        ],
    ]);

    $response = $this->get(route('activities.export.gpx', $activity));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/gpx+xml; charset=UTF-8');

    expect((string) $response->headers->get('Content-Disposition'))
        ->toBe('attachment; filename="2026-11-21-cornwall-park-5k.gpx"');

    $xml = new SimpleXMLElement($response->getContent());
    expect(count($xml->trk->trkseg->trkpt))->toBe(2)
        ->and((string) $xml->trk->trkseg->trkpt[0]->ele)->toBe('84.5');
});

it('returns 404 when exporting gpx for an indoor activity with no gps stream', function () {
    $activity = Activity::factory()->indoor()->create();

    $this->get(route('activities.export.gpx', $activity))
        ->assertNotFound();
});

it('downloads the cached original fit archive with exact bytes', function () {
    Storage::fake('local');

    $activity = Activity::factory()->create([
        'provider' => 'garmin_personal',
        'garmin_activity_id' => 55443322,
        'external_id' => '55443322',
        'name' => 'Morning Tempo',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 06:15:00'),
    ]);

    $archiveBytes = "PK\x03\x04\x14\x00original-garmin-fit-zip-bytes";
    Storage::disk('local')->put('garmin/fits/55443322.zip', $archiveBytes);

    $response = $this->get(route('activities.export.original', $activity));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/zip');

    expect((string) $response->headers->get('Content-Disposition'))
        ->toContain('2026-11-20-morning-tempo.zip')
        ->and($response->streamedContent())->toBe($archiveBytes);
});

it('returns 404 when the cached original fit archive is missing', function () {
    Storage::fake('local');

    $activity = Activity::factory()->create([
        'provider' => 'garmin_personal',
        'garmin_activity_id' => 77665544,
        'external_id' => '77665544',
    ]);

    $this->get(route('activities.export.original', $activity))
        ->assertNotFound();
});
