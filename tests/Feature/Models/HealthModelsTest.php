<?php

use App\Models\Activity;
use App\Models\ActivityStream;
use App\Models\BestEffort;
use App\Models\DailyMetric;
use App\Models\FitnessAgeRecord;
use App\Models\HeartRateZone;
use App\Models\HrvRecord;
use App\Models\SleepRecord;
use App\Models\SyncRun;
use App\Models\Vo2MaxRecord;
use App\Models\WeightMeasurement;
use Carbon\CarbonImmutable;

it('persists records with expected types across all health and telemetry factories', function () {
    $dailyMetric = DailyMetric::factory()->create(['calendar_date' => '2026-11-21']);
    $sleepRecord = SleepRecord::factory()->create(['calendar_date' => '2026-11-21']);
    $hrvRecord = HrvRecord::factory()->create(['calendar_date' => '2026-11-21']);
    $vo2MaxRecord = Vo2MaxRecord::factory()->create(['calendar_date' => '2026-11-21', 'sport' => 'running']);
    $fitnessAgeRecord = FitnessAgeRecord::factory()->create(['calendar_date' => '2026-11-21']);
    $weightMeasurement = WeightMeasurement::factory()->create(['calendar_date' => '2026-11-21']);
    $heartRateZone = HeartRateZone::factory()->create(['sport' => 'RUNNING']);
    $activity = Activity::factory()->create();
    $activityStream = ActivityStream::factory()->for($activity)->create();
    $bestEffort = BestEffort::factory()->for($activity)->create();
    $syncRun = SyncRun::factory()->create();

    expect($dailyMetric->fresh()->calendar_date)->toBe('2026-11-21')
        ->and($sleepRecord->fresh()->sleep_start_local)->toBeInstanceOf(CarbonImmutable::class)
        ->and($hrvRecord->fresh()->calendar_date)->toBe('2026-11-21')
        ->and($vo2MaxRecord->fresh()->sport)->toBe('running')
        ->and($fitnessAgeRecord->fresh()->components)->toBeArray()
        ->and($weightMeasurement->fresh()->measured_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($heartRateZone->fresh()->sport)->toBe('RUNNING')
        ->and($activity->fresh()->started_at_utc)->toBeInstanceOf(CarbonImmutable::class)
        ->and($activityStream->fresh()->polyline)->toBeArray()
        ->and($bestEffort->fresh()->achieved_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($syncRun->fresh()->started_at)->toBeInstanceOf(CarbonImmutable::class);
});

it('filters activities by sport and effective biking discipline scopes', function () {
    $run = Activity::factory()->create();
    $road = Activity::factory()->roadBiking()->create();
    $mtb = Activity::factory()->mountainBiking()->create();
    $genericOther = Activity::factory()->otherBiking()->create();
    $reclassifiedToRoad = Activity::factory()->otherBiking()->create([
        'discipline_override' => 'road',
    ]);
    $reclassifiedToMtb = Activity::factory()->otherBiking()->create([
        'discipline_override' => 'mtb',
    ]);

    expect(Activity::query()->running()->pluck('id')->all())
        ->toBe([$run->id]);

    expect(Activity::query()->biking()->pluck('id')->all())
        ->toEqualCanonicalizing([
            $road->id,
            $mtb->id,
            $genericOther->id,
            $reclassifiedToRoad->id,
            $reclassifiedToMtb->id,
        ]);

    expect(Activity::query()->roadBiking()->pluck('id')->all())
        ->toEqualCanonicalizing([$road->id, $reclassifiedToRoad->id]);

    expect(Activity::query()->mountainBiking()->pluck('id')->all())
        ->toEqualCanonicalizing([$mtb->id, $reclassifiedToMtb->id]);

    expect(Activity::query()->otherBiking()->pluck('id')->all())
        ->toBe([$genericOther->id]);

    expect($run->effectiveDiscipline())->toBe('road')
        ->and($road->effectiveDiscipline())->toBe('road')
        ->and($genericOther->effectiveDiscipline())->toBe('other')
        ->and($reclassifiedToRoad->effectiveDiscipline())->toBe('road');
});

it('computes effective seconds on best efforts and cascades deletes from activities', function () {
    $activity = Activity::factory()->create();
    $stream = ActivityStream::factory()->for($activity)->create();
    $rawEffort = BestEffort::factory()->for($activity)->create([
        'distance_key' => '5k',
        'elapsed_seconds' => 1215.5,
        'override_seconds' => null,
    ]);
    $overriddenEffort = BestEffort::factory()->for($activity)->create([
        'distance_key' => '10k',
        'elapsed_seconds' => 2550.0,
        'override_seconds' => 2512.4,
    ]);

    expect($rawEffort->effectiveSeconds())->toBe(1215.5)
        ->and($overriddenEffort->effectiveSeconds())->toBe(2512.4)
        ->and($activity->stream?->id)->toBe($stream->id)
        ->and($activity->bestEfforts)->toHaveCount(2)
        ->and($stream->activity->id)->toBe($activity->id)
        ->and($rawEffort->activity->id)->toBe($activity->id);

    $activity->delete();

    expect(ActivityStream::query()->find($stream->id))->toBeNull()
        ->and(BestEffort::query()->find($rawEffort->id))->toBeNull()
        ->and(BestEffort::query()->find($overriddenEffort->id))->toBeNull();
});
