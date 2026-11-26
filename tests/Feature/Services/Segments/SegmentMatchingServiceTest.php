<?php

use App\Jobs\GeocodeSegmentJob;
use App\Models\Activity;
use App\Models\ActivityStream;
use App\Models\Segment;
use App\Models\SegmentEffort;
use App\Models\WeightMeasurement;
use App\Services\Geocoding\ReverseGeocodingService;
use App\Services\Ingestion\TelemetryPersistenceService;
use App\Services\Segments\SegmentMatchingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Splitline\Core\Data\ActivityStreamData;

beforeEach(function () {
    Cache::flush();
    config([
        'services.nominatim.enabled' => false,
        'services.nominatim.auto_sync' => false,
        'services.open_meteo.enabled' => false,
    ]);
});

/**
 * Build a realistic outdoor ride stream with flat approach + 1500m @ 6.5% climb + flat finish.
 *
 * @return list<array{
 *     time: float,
 *     distance: float,
 *     altitude: float,
 *     speed: float,
 *     hr: int,
 *     cadence: int,
 *     power: int,
 *     lat: float,
 *     lng: float
 * }>
 */
function makeClimbRideSeries(
    float $climbSpeedMps = 4.5,
    int $avgPower = 280,
    int $avgHr = 166,
    float $latOffset = 0.0,
    float $lngOffset = 0.0,
    int $repeats = 1,
): array {
    $series = [];
    $baseLat = -36.8800 + $latOffset;
    $baseLng = 174.7600 + $lngOffset;
    $time = 0.0;
    $dist = 0.0;
    $alt = 40.0;

    // 300m flat approach
    for ($i = 0; $i < 15; $i++) {
        $series[] = [
            'time' => round($time, 1),
            'distance' => round($dist, 1),
            'altitude' => round($alt, 1),
            'speed' => 8.0,
            'hr' => 130,
            'cadence' => 88,
            'power' => 170,
            'lat' => round($baseLat + ($i * 20.0 / 111320.0), 6),
            'lng' => round($baseLng, 6),
        ];
        $dist += 20.0;
        $time += 2.5;
    }

    $climbBaseLat = $baseLat + (300.0 / 111320.0);

    for ($lap = 0; $lap < $repeats; $lap++) {
        // Climb north 1500m gaining 97.5m (6.5% grade, 75 steps of 20m)
        for ($i = 0; $i <= 75; $i++) {
            $stepDist = $i * 20.0;
            $series[] = [
                'time' => round($time, 1),
                'distance' => round($dist, 1),
                'altitude' => round(40.0 + ($stepDist * 0.065), 2),
                'speed' => $climbSpeedMps,
                'hr' => $avgHr + ($i % 5),
                'cadence' => 82,
                'power' => $avgPower,
                'lat' => round($climbBaseLat + ($stepDist / 111320.0), 6),
                'lng' => round($baseLng, 6),
            ];
            $dist += 20.0;
            $time += 20.0 / $climbSpeedMps;
        }

        // Flat summit + descent back to climb foot if more repeats remain
        if ($lap < $repeats - 1) {
            for ($i = 1; $i <= 75; $i++) {
                $backDist = (75 - $i) * 20.0;
                $series[] = [
                    'time' => round($time, 1),
                    'distance' => round($dist, 1),
                    'altitude' => round(40.0 + ($backDist * 0.065), 2),
                    'speed' => 12.0,
                    'hr' => 128,
                    'cadence' => 60,
                    'power' => 90,
                    'lat' => round($climbBaseLat + ($backDist / 111320.0), 6),
                    'lng' => round($baseLng + 0.00015, 6),
                ];
                $dist += 20.0;
                $time += 20.0 / 12.0;
            }
        }
    }

    // 300m flat plateau after summit
    $summitLat = $climbBaseLat + (1500.0 / 111320.0);
    for ($i = 1; $i <= 15; $i++) {
        $series[] = [
            'time' => round($time, 1),
            'distance' => round($dist, 1),
            'altitude' => 137.5,
            'speed' => 9.0,
            'hr' => 145,
            'cadence' => 86,
            'power' => 180,
            'lat' => round($summitLat + ($i * 20.0 / 111320.0), 6),
            'lng' => round($baseLng, 6),
        ];
        $dist += 20.0;
        $time += 2.2;
    }

    return $series;
}

it('auto-detects and deduplicates climbs across two rides, computes VAM/W/kg, and ranks efforts with PR', function () {
    WeightMeasurement::factory()->create([
        'provider' => 'garmin_personal',
        'calendar_date' => '2026-11-01',
        'weight_grams' => 70000, // 70.0 kg
    ]);

    $ride1 = Activity::factory()->roadBiking()->create([
        'name' => 'Morning Hills',
        'location_name' => 'Waitakere, Auckland',
        'started_at_local' => CarbonImmutable::parse('2026-11-05 07:00:00'),
        'distance_meters' => 2500.0,
    ]);
    $stream1 = ActivityStream::factory()->create([
        'activity_id' => $ride1->id,
        'series' => makeClimbRideSeries(climbSpeedMps: 4.0, avgPower: 245, avgHr: 162),
    ]);

    $ride2 = Activity::factory()->roadBiking()->create([
        'name' => 'Threshold Climb Test',
        'location_name' => 'Waitakere, Auckland',
        'started_at_local' => CarbonImmutable::parse('2026-11-12 07:00:00'),
        'distance_meters' => 2500.0,
    ]);
    $stream2 = ActivityStream::factory()->create([
        'activity_id' => $ride2->id,
        'series' => makeClimbRideSeries(climbSpeedMps: 5.0, avgPower: 280, avgHr: 174, latOffset: 0.00003),
    ]);

    $matcher = app(SegmentMatchingService::class);
    $res1 = $matcher->scanActivity($ride1, $stream1);
    $res2 = $matcher->scanActivity($ride2, $stream2);

    expect($res1['climbs_created'])->toBe(1)
        ->and($res1['efforts_recorded'])->toBe(1)
        ->and($res2['climbs_created'])->toBe(0) // deduplicated against existing climb!
        ->and($res2['efforts_recorded'])->toBe(1)
        ->and(Segment::query()->count())->toBe(1)
        ->and($ride1->fresh()?->segments_scanned_at)->not->toBeNull()
        ->and($ride2->fresh()?->segments_scanned_at)->not->toBeNull();

    $segment = Segment::query()->firstOrFail();
    $efforts = SegmentEffort::query()
        ->where('segment_id', $segment->id)
        ->orderBy('rank')
        ->get();

    expect($segment->kind)->toBe(Segment::KIND_CLIMB)
        ->and($segment->climb_category)->toBe('cat4')
        ->and($segment->effort_count)->toBe(2)
        ->and($efforts)->toHaveCount(2)
        ->and($efforts[0]->activity_id)->toBe($ride2->id) // faster ride is PR (#1)
        ->and($efforts[0]->rank)->toBe(1)
        ->and($efforts[0]->is_pr)->toBeTrue()
        ->and($efforts[0]->w_per_kg)->toBeGreaterThanOrEqual(3.9)
        ->and($efforts[0]->w_per_kg)->toBeLessThanOrEqual(4.0)
        ->and($efforts[0]->vam)->toBeGreaterThan(1000.0)
        ->and($efforts[1]->activity_id)->toBe($ride1->id)
        ->and($efforts[1]->rank)->toBe(2)
        ->and($efforts[1]->is_pr)->toBeFalse()
        ->and($efforts[1]->w_per_kg)->toBeGreaterThanOrEqual(3.4)
        ->and($efforts[1]->w_per_kg)->toBeLessThanOrEqual(3.5);
});

it('records every non-overlapping pass on a hill-repeat activity cleanly', function () {
    $ride = Activity::factory()->roadBiking()->create([
        'name' => '3x Hill Repeats',
        'started_at_local' => CarbonImmutable::parse('2026-11-15 07:00:00'),
        'distance_meters' => 8000.0,
    ]);
    $stream = ActivityStream::factory()->create([
        'activity_id' => $ride->id,
        'series' => makeClimbRideSeries(climbSpeedMps: 4.5, repeats: 3),
    ]);

    $matcher = app(SegmentMatchingService::class);
    $summary = $matcher->scanActivity($ride, $stream);

    $segment = Segment::query()->firstOrFail();
    $efforts = SegmentEffort::query()
        ->where('segment_id', $segment->id)
        ->orderBy('start_index')
        ->get();

    expect($summary['climbs_created'])->toBe(1)
        ->and($summary['efforts_recorded'])->toBe(3)
        ->and($segment->effort_count)->toBe(3)
        ->and($efforts)->toHaveCount(3)
        ->and($efforts[0]->start_index)->toBeLessThan($efforts[1]->start_index)
        ->and($efforts[1]->start_index)->toBeLessThan($efforts[2]->start_index);
});

it('creates a custom segment, backfills historical activities synchronously, and preserves custom segments & climb overrides on rebuild', function () {
    $ride1 = Activity::factory()->roadBiking()->create([
        'name' => 'Old Ride',
        'started_at_local' => CarbonImmutable::parse('2026-10-01 07:00:00'),
        'distance_meters' => 2500.0,
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $ride1->id,
        'series' => makeClimbRideSeries(climbSpeedMps: 4.2, avgPower: 250),
    ]);

    $ride2 = Activity::factory()->roadBiking()->create([
        'name' => 'New Ride',
        'started_at_local' => CarbonImmutable::parse('2026-11-10 07:00:00'),
        'distance_meters' => 2500.0,
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $ride2->id,
        'series' => makeClimbRideSeries(climbSpeedMps: 4.8, avgPower: 290),
    ]);

    $matcher = app(SegmentMatchingService::class);
    $matcher->scanUnscanned();

    // Customize & star the auto-detected climb
    $climb = Segment::query()->climbs()->firstOrFail();
    $climb->update([
        'name_override' => 'My Favorite Hill',
        'is_starred' => true,
    ]);

    // Create a custom 600m segment inside ride2 (indices 20..50 = 600m mid-climb slice)
    $custom = $matcher->createCustomSegment($ride2, 20, 50, 'Mid-Climb Kick');

    expect($custom->kind)->toBe(Segment::KIND_CUSTOM)
        ->and($custom->displayName())->toBe('Mid-Climb Kick')
        ->and($custom->effort_count)->toBe(2) // matched both ride2 and historical ride1!
        ->and(SegmentEffort::query()->where('segment_id', $custom->id)->count())->toBe(2);

    // Rebuild all climbs & efforts
    $rebuildSummary = $matcher->rebuildAll();

    expect($rebuildSummary['scanned'])->toBe(2)
        ->and(Segment::query()->whereKey($custom->id)->exists())->toBeTrue()
        ->and($custom->fresh()?->effort_count)->toBe(2);

    $rebuiltClimb = Segment::query()->climbs()->firstOrFail();

    expect($rebuiltClimb->name_override)->toBe('My Favorite Hill')
        ->and($rebuiltClimb->is_starred)->toBeTrue()
        ->and($rebuiltClimb->effort_count)->toBe(2);
});

it('auto-scans streams for climbs and segments during TelemetryPersistenceService::upsertStream', function () {
    Queue::fake([GeocodeSegmentJob::class]);
    config([
        'services.nominatim.enabled' => true,
        'services.nominatim.auto_sync' => true,
    ]);

    $activity = Activity::factory()->roadBiking()->create([
        'name' => 'Synced Climb Ride',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
        'distance_meters' => 2500.0,
    ]);

    $series = makeClimbRideSeries(climbSpeedMps: 4.6, avgPower: 265);
    $polyline = array_map(static fn (array $pt): array => [$pt['lat'], $pt['lng']], $series);

    $streamData = new ActivityStreamData(
        series: $series,
        polyline: $polyline,
        laps: [],
        kmSplits: [],
        records: [],
    );

    app(TelemetryPersistenceService::class)->upsertStream($activity, $streamData);

    expect($activity->fresh()?->segments_scanned_at)->not->toBeNull()
        ->and(Segment::query()->climbs()->count())->toBe(1)
        ->and(SegmentEffort::query()->where('activity_id', $activity->id)->count())->toBe(1);

    Queue::assertPushed(GeocodeSegmentJob::class);
});

it('enriches segments via Nominatim feature tier without overwriting name_override', function () {
    Http::preventStrayRequests();
    Sleep::fake();
    config([
        'services.nominatim.enabled' => true,
        'services.nominatim.base_url' => 'https://nominatim.openstreetmap.org',
        'services.nominatim.rate_limit_ms' => 1100,
    ]);

    Http::fake([
        'https://nominatim.openstreetmap.org/reverse*' => Http::response([
            'category' => 'highway',
            'type' => 'secondary',
            'name' => 'Scenic Drive',
            'address' => [
                'road' => 'Scenic Drive',
                'suburb' => 'Titirangi',
                'city' => 'Auckland',
            ],
        ]),
    ]);

    $climb = Segment::factory()->climb('cat4')->create([
        'name' => 'Auckland Climb (1.70 km @ 6.6%)',
        'name_override' => null,
        'geocoded_at' => null,
    ]);

    $customNamed = Segment::factory()->custom()->create([
        'name' => 'My Personal Sprint',
        'name_override' => 'My Personal Sprint',
        'geocoded_at' => null,
    ]);

    $geocoder = app(ReverseGeocodingService::class);

    expect($geocoder->enrichSegment($climb))->toBeTrue()
        ->and($climb->fresh()?->place_label)->toBe('Scenic Drive · Auckland')
        ->and($climb->fresh()?->name)->toBe('Scenic Drive Climb')
        ->and($climb->fresh()?->geocoded_at)->not->toBeNull();

    expect($geocoder->enrichSegment($customNamed))->toBeTrue()
        ->and($customNamed->fresh()?->place_label)->toBe('Scenic Drive · Auckland')
        ->and($customNamed->fresh()?->name_override)->toBe('My Personal Sprint')
        ->and($customNamed->fresh()?->displayName())->toBe('My Personal Sprint');
});
