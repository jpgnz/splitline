<?php

use App\Models\Activity;
use App\Models\ActivityDerivedMetric;
use App\Models\ActivityStream;
use App\Models\ActivityWeather;
use App\Models\BestEffort;
use App\Models\DailyMetric;
use App\Models\HrvRecord;
use App\Models\RouteCluster;
use App\Models\Segment;
use App\Models\SleepRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('refuses to overwrite an existing target database without force', function () {
    $tempDb = '/tmp/opencode/demo-seed-existing-test.sqlite';
    @mkdir(dirname($tempDb), 0755, true);
    file_put_contents($tempDb, '');

    try {
        $this->artisan('demo:seed', [
            '--database' => $tempDb,
            '--skip-weather' => true,
            '--skip-geocode' => true,
        ])
            ->expectsOutputToContain('Target database already exists')
            ->assertExitCode(1);
    } finally {
        @unlink($tempDb);
    }
});

it('rejects out-of-range days and invalid end-date values', function () {
    $this->artisan('demo:seed', [
        '--database' => '/tmp/opencode/demo-seed-invalid-days.sqlite',
        '--days' => 10,
    ])
        ->expectsOutputToContain('--days option must be between 90 and 1825')
        ->assertExitCode(1);

    $this->artisan('demo:seed', [
        '--database' => '/tmp/opencode/demo-seed-invalid-date.sqlite',
        '--end-date' => 'not-a-date',
    ])
        ->expectsOutputToContain('--end-date option must be a valid YYYY-MM-DD date')
        ->assertExitCode(1);
});

it('seeds an isolated sqlite demo database with streams, weather, matched routes, climbs, and health history', function () {
    $originalDb = config('database.connections.sqlite.database');
    $tempDb = '/tmp/opencode/demo-seed-e2e-test.sqlite';
    $routeCachePath = storage_path('app/demo-routes-cache.json');
    $originalCacheBytes = is_file($routeCachePath) ? file_get_contents($routeCachePath) : null;

    @mkdir(dirname($tempDb), 0755, true);

    // Build a realistic ~4.5 km hilly loop GeoJSON (with a clear sustained climb + descent) for faked BRouter responses.
    $coords = [];
    $steps = 120;
    $centerLat = 40.0150;
    $centerLng = -105.2705;

    for ($i = 0; $i <= $steps; $i++) {
        $theta = ($i / $steps) * 2.0 * M_PI;
        $lat = $centerLat + (0.0085 * sin($theta));
        $lng = $centerLng + (0.0110 * (1.0 - cos($theta)));
        $ele = 1650.0 + (160.0 * sin(($i / $steps) * M_PI));
        $coords[] = [round($lng, 6), round($lat, 6), round($ele, 1)];
    }

    Http::preventStrayRequests();
    Http::fake([
        'https://brouter.de/brouter*' => Http::response([
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'properties' => ['track-length' => '4800', 'filtered ascend' => '160'],
                    'geometry' => [
                        'type' => 'LineString',
                        'coordinates' => $coords,
                    ],
                ],
            ],
        ], 200),
    ]);

    try {
        $this->artisan('demo:seed', [
            '--database' => $tempDb,
            '--force' => true,
            '--fresh-routes' => true,
            '--skip-weather' => true,
            '--skip-geocode' => true,
            '--days' => 90,
            '--end-date' => '2026-11-27',
            '--seed' => 12345,
        ])->assertExitCode(0);

        expect(Activity::query()->count())->toBeGreaterThan(50)
            ->and(ActivityStream::query()->count())->toBe(Activity::query()->count())
            ->and(ActivityDerivedMetric::query()->count())->toBe(Activity::query()->count())
            ->and(ActivityWeather::query()->count())->toBeGreaterThan(40)
            ->and(BestEffort::query()->count())->toBeGreaterThan(10)
            ->and(RouteCluster::query()->matched()->count())->toBeGreaterThan(0)
            ->and(Segment::query()->count())->toBeGreaterThan(0)
            ->and(DailyMetric::query()->count())->toBe(90)
            ->and(SleepRecord::query()->count())->toBe(90)
            ->and(HrvRecord::query()->count())->toBe(90)
            ->and(Activity::query()->where('jump_count', '>', 0)->exists())->toBeTrue();
    } finally {
        config(['database.connections.sqlite.database' => $originalDb]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        foreach ([$tempDb, "{$tempDb}-wal", "{$tempDb}-shm"] as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        if ($originalCacheBytes !== null) {
            file_put_contents($routeCachePath, $originalCacheBytes);
        } elseif (is_file($routeCachePath)) {
            @unlink($routeCachePath);
        }
    }
});
