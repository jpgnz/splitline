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

it('auto-scans streams for climbs during TelemetryPersistenceService::upsertStream and defers geocoding until min_efforts is met', function () {
    Queue::fake([GeocodeSegmentJob::class]);
    config([
        'services.nominatim.enabled' => true,
        'services.nominatim.auto_sync' => true,
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

    // First 4 rides stay below the default min_efforts (5) threshold -> climb tracked, NOT yet geocoded
    for ($n = 1; $n <= 4; $n++) {
        $activity = Activity::factory()->roadBiking()->create([
            'name' => "Synced Climb Ride #{$n}",
            'started_at_local' => CarbonImmutable::parse('2026-11-10 07:00:00')->addDays($n),
            'distance_meters' => 2500.0,
        ]);

        app(TelemetryPersistenceService::class)->upsertStream($activity, $streamData);

        expect($activity->fresh()?->segments_scanned_at)->not->toBeNull();
    }

    expect(Segment::query()->climbs()->count())->toBe(1)
        ->and(Segment::query()->climbs()->firstOrFail()->effort_count)->toBe(4)
        ->and(Segment::query()->visible()->count())->toBe(0);

    Queue::assertNothingPushed();

    // 5th ride crosses default min_efforts (5) -> climb becomes visible and GeocodeSegmentJob is dispatched
    $fifthRide = Activity::factory()->roadBiking()->create([
        'name' => 'Synced Climb Ride #5',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
        'distance_meters' => 2500.0,
    ]);

    app(TelemetryPersistenceService::class)->upsertStream($fifthRide, $streamData);

    expect(Segment::query()->climbs()->firstOrFail()->effort_count)->toBe(5)
        ->and(Segment::query()->visible()->count())->toBe(1);

    Queue::assertPushed(GeocodeSegmentJob::class, 1);
});

it('deduplicates shifted-endpoint climbs along the same road while keeping genuinely longer summit extensions separate', function () {
    $buildRoadClimb = static function (float $startOffsetM, float $climbLengthM, float $grade = 0.08): array {
        $series = [];
        $baseLat = -43.5700;
        $baseLng = 172.6200;
        $dist = 0.0;
        $time = 0.0;

        // Flat approach from 0 to startOffsetM + 200m
        $approachSteps = (int) round(($startOffsetM + 200.0) / 20.0);

        for ($i = 0; $i < $approachSteps; $i++) {
            $pos = $i * 20.0;
            $series[] = [
                'time' => $time,
                'distance' => $dist,
                'altitude' => 30.0,
                'speed' => 8.0,
                'lat' => round($baseLat + ($pos / 111320.0), 6),
                'lng' => $baseLng,
            ];
            $dist += 20.0;
            $time += 2.5;
        }

        // Climb for climbLengthM
        $climbStartPos = $startOffsetM + 200.0;
        $climbSteps = (int) round($climbLengthM / 20.0);

        for ($i = 0; $i <= $climbSteps; $i++) {
            $d = $i * 20.0;
            $series[] = [
                'time' => $time,
                'distance' => $dist,
                'altitude' => round(30.0 + ($d * $grade), 2),
                'speed' => 4.5,
                'lat' => round($baseLat + (($climbStartPos + $d) / 111320.0), 6),
                'lng' => $baseLng,
            ];
            $dist += 20.0;
            $time += 4.4;
        }

        // Flat plateau
        $topAlt = 30.0 + ($climbLengthM * $grade);
        $topPos = $climbStartPos + $climbLengthM;

        for ($i = 1; $i <= 10; $i++) {
            $series[] = [
                'time' => $time,
                'distance' => $dist,
                'altitude' => round($topAlt, 2),
                'speed' => 8.0,
                'lat' => round($baseLat + (($topPos + ($i * 20.0)) / 111320.0), 6),
                'lng' => $baseLng,
            ];
            $dist += 20.0;
            $time += 2.5;
        }

        return $series;
    };

    // Ride 1: 1200m climb starting at offset 0m
    $ride1 = Activity::factory()->roadBiking()->create(['started_at_local' => CarbonImmutable::parse('2026-10-01 07:00:00')]);
    ActivityStream::factory()->create(['activity_id' => $ride1->id, 'series' => $buildRoadClimb(0.0, 1200.0)]);

    // Ride 2: Same climb starting 180m earlier and extending 120m further (1500m total = 25% longer, >150m start shift!)
    // Old dedup created a duplicate here; directional polyline overlap must merge them into ONE climb.
    $ride2 = Activity::factory()->roadBiking()->create(['started_at_local' => CarbonImmutable::parse('2026-10-02 07:00:00')]);
    ActivityStream::factory()->create(['activity_id' => $ride2->id, 'series' => $buildRoadClimb(-180.0, 1500.0)]);

    // Ride 3: Longer continuation going 1500m PAST the first summit (2700m total, endpoint 1500m further!)
    // Must stay a SEPARATE climb (like Dyers Pass vs Dyers Pass + Summit Rd).
    $ride3 = Activity::factory()->roadBiking()->create(['started_at_local' => CarbonImmutable::parse('2026-10-03 07:00:00')]);
    ActivityStream::factory()->create(['activity_id' => $ride3->id, 'series' => $buildRoadClimb(0.0, 2700.0, 0.06)]);

    $matcher = app(SegmentMatchingService::class);
    $matcher->scanUnscanned();

    $climbs = Segment::query()->climbs()->orderBy('distance_meters')->get();

    expect($climbs)->toHaveCount(2)
        ->and($climbs[0]->detection_count)->toBe(2) // rides 1 & 2 merged cleanly!
        ->and($climbs[1]->detection_count)->toBe(1) // ride 3 stayed separate!
        ->and($climbs[1]->distance_meters)->toBeGreaterThan(2400.0);
});

it('suppresses one-off partial sub-climbs and rejects flat-road barometer glitch climbs via median verification', function () {
    $buildTrack = static function (float $climbLenM, float $grade, int $totalLenM = 1600): array {
        $series = [];
        $baseLat = -43.5700;
        $baseLng = 172.6200;
        $dist = 0.0;
        $time = 0.0;
        $steps = (int) round($totalLenM / 20.0);
        $climbSteps = (int) round($climbLenM / 20.0);

        for ($i = 0; $i <= $steps; $i++) {
            $alt = $i <= $climbSteps ? (30.0 + ($i * 20.0 * $grade)) : (30.0 + ($climbLenM * $grade));
            $series[] = [
                'time' => $time,
                'distance' => $dist,
                'altitude' => round($alt, 2),
                'speed' => 5.0,
                'lat' => round($baseLat + ($dist / 111320.0), 6),
                'lng' => $baseLng,
            ];
            $dist += 20.0;
            $time += 4.0;
        }

        return $series;
    };

    // Scenario A: 1 ride turns off at 700m (partial fragment), while 5 rides climb the full 1500m hill.
    $partialRide = Activity::factory()->roadBiking()->create(['started_at_local' => CarbonImmutable::parse('2026-09-01 07:00:00')]);
    ActivityStream::factory()->create(['activity_id' => $partialRide->id, 'series' => $buildTrack(700.0, 0.08, 700)]);

    for ($n = 1; $n <= 5; $n++) {
        $fullRide = Activity::factory()->roadBiking()->create(['started_at_local' => CarbonImmutable::parse('2026-09-02 07:00:00')->addDays($n)]);
        ActivityStream::factory()->create(['activity_id' => $fullRide->id, 'series' => $buildTrack(1500.0, 0.08, 1600)]);
    }

    // Scenario B: On a different road, 1 ride has a barometer glitch (+100m over 600m), followed by 5 flat rides (+0m).
    $buildFlatOrGlitch = static function (bool $glitch): array {
        $series = [];
        $baseLat = -43.5200;
        $baseLng = 172.5500;
        $dist = 0.0;
        $time = 0.0;

        for ($i = 0; $i <= 40; $i++) {
            $alt = $glitch && $i >= 5 && $i <= 35 ? (20.0 + (($i - 5) * 20.0 * 0.16)) : 20.0;
            $series[] = [
                'time' => $time,
                'distance' => $dist,
                'altitude' => round($alt, 2),
                'speed' => 7.0,
                'lat' => round($baseLat + ($dist / 111320.0), 6),
                'lng' => $baseLng,
            ];
            $dist += 20.0;
            $time += 3.0;
        }

        return $series;
    };

    $glitchRide = Activity::factory()->roadBiking()->create(['started_at_local' => CarbonImmutable::parse('2026-09-20 07:00:00')]);
    ActivityStream::factory()->create(['activity_id' => $glitchRide->id, 'series' => $buildFlatOrGlitch(true)]);

    for ($n = 1; $n <= 5; $n++) {
        $flatRide = Activity::factory()->roadBiking()->create(['started_at_local' => CarbonImmutable::parse('2026-09-21 07:00:00')->addDays($n)]);
        ActivityStream::factory()->create(['activity_id' => $flatRide->id, 'series' => $buildFlatOrGlitch(false)]);
    }

    app(SegmentMatchingService::class)->scanUnscanned();

    $visible = Segment::query()->visible()->get();
    $unverified = Segment::query()->climbs()->where('is_climb_verified', false)->get();
    $subClimbs = Segment::query()->climbs()->where('is_sub_climb', true)->get();

    // Only the real 1500m full climb is visible! Partial 700m fragment is hidden as a 1-detection sub-climb,
    // and the flat-road barometer glitch is rejected as is_climb_verified = false.
    expect($visible)->toHaveCount(1)
        ->and($visible[0]->distance_meters)->toBeGreaterThan(1300.0)
        ->and($visible[0]->effort_count)->toBe(5)
        ->and($subClimbs)->toHaveCount(1)
        ->and($subClimbs[0]->detection_count)->toBe(1)
        ->and($unverified)->toHaveCount(1)
        ->and($unverified[0]->effort_count)->toBe(6);
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
