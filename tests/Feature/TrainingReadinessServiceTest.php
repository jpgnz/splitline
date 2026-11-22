<?php

use App\Models\Activity;
use App\Models\DailyMetric;
use App\Models\HrvRecord;
use App\Models\SleepRecord;
use App\Services\Health\TrainingReadinessService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-22 08:00:00', 'Pacific/Auckland'));
});

it('returns an empty readiness payload when no underlying telemetry exists', function () {
    $today = CarbonImmutable::parse('2026-11-22', 'Pacific/Auckland')->startOfDay();

    $result = app(TrainingReadinessService::class)->forDate(
        selectedDate: $today,
        today: $today,
        dailyMetric: null,
        sleepRecord: null,
        hrvRecord: null,
        timezone: 'Pacific/Auckland',
    );

    expect($result['score'])->toBeNull()
        ->and($result['level'])->toBeNull()
        ->and($result['tone'])->toBe('neutral')
        ->and($result['factors']['sleep']['label'])->toBe('—')
        ->and($result['factors']['hrvStatus']['label'])->toBe('—');
});

it('computes high or prime readiness when rested with strong sleep, balanced HRV, low stress, and optimal load', function () {
    $today = CarbonImmutable::parse('2026-11-22', 'Pacific/Auckland')->startOfDay();

    foreach (['2026-11-19', '2026-11-20', '2026-11-21'] as $priorDate) {
        SleepRecord::factory()->create([
            'calendar_date' => $priorDate,
            'sleep_seconds' => 28800,
            'sleep_score' => 89,
            'sleep_start_local' => CarbonImmutable::parse($priorDate)->subDay()->setTime(22, 30),
            'sleep_end_local' => CarbonImmutable::parse($priorDate)->setTime(6, 30),
        ]);

        DailyMetric::factory()->create([
            'calendar_date' => $priorDate,
            'avg_stress' => 15,
        ]);
    }

    $sleep = SleepRecord::factory()->create([
        'calendar_date' => '2026-11-22',
        'sleep_seconds' => 29400,
        'sleep_score' => 92,
        'sleep_start_local' => CarbonImmutable::parse('2026-11-21 22:30:00'),
        'sleep_end_local' => CarbonImmutable::parse('2026-11-22 06:40:00'),
        'raw_payload' => ['sleepScores' => ['overall' => ['value' => 92, 'qualifierKey' => 'EXCELLENT']]],
    ]);

    $hrv = HrvRecord::factory()->create([
        'calendar_date' => '2026-11-22',
        'last_night_avg' => 56,
        'weekly_avg' => 55,
        'status' => 'BALANCED',
        'baseline_balanced_low' => 44,
        'baseline_balanced_upper' => 58,
    ]);

    $daily = DailyMetric::factory()->create([
        'calendar_date' => '2026-11-22',
        'avg_stress' => 14,
    ]);

    // Steady weekly load with 2 days rest before today
    foreach (['2026-11-01', '2026-11-08', '2026-11-15', '2026-11-19'] as $activityDate) {
        Activity::factory()->create([
            'started_at_local' => CarbonImmutable::parse($activityDate.' 07:00:00'),
            'duration_seconds' => 3000.0,
            'training_load' => 120.0,
            'aerobic_te' => 3.3,
        ]);
    }

    $result = app(TrainingReadinessService::class)->forDate(
        selectedDate: $today,
        today: $today,
        dailyMetric: $daily,
        sleepRecord: $sleep,
        hrvRecord: $hrv,
        timezone: 'Pacific/Auckland',
    );

    expect($result['score'])->toBeGreaterThanOrEqual(85)
        ->and($result['level'])->toBeIn(['High', 'Prime'])
        ->and($result['factors']['sleep']['label'])->toBe('Excellent')
        ->and($result['factors']['recovery']['label'])->toBe('No Need')
        ->and($result['factors']['hrvStatus']['label'])->toBe('Balanced')
        ->and($result['factors']['recentStress']['label'])->toBe('Low');
});

it('penalizes readiness after heavy recent load, poor sleep, unbalanced HRV, and long awake gaps', function () {
    $today = CarbonImmutable::parse('2026-11-22', 'Pacific/Auckland')->startOfDay();

    // Missing sleep on Nov 21 creates a ~39h awake gap before Nov 22 sleep
    SleepRecord::factory()->create([
        'calendar_date' => '2026-11-20',
        'sleep_seconds' => 18000,
        'sleep_score' => 48,
        'sleep_start_local' => CarbonImmutable::parse('2026-11-20 01:00:00'),
        'sleep_end_local' => CarbonImmutable::parse('2026-11-20 06:00:00'),
    ]);

    $sleep = SleepRecord::factory()->create([
        'calendar_date' => '2026-11-22',
        'sleep_seconds' => 21600,
        'sleep_score' => 54,
        'sleep_start_local' => CarbonImmutable::parse('2026-11-21 22:00:00'),
        'sleep_end_local' => CarbonImmutable::parse('2026-11-22 04:00:00'),
        'raw_payload' => ['sleepScores' => ['overall' => ['value' => 54, 'qualifierKey' => 'POOR']]],
    ]);

    $hrv = HrvRecord::factory()->create([
        'calendar_date' => '2026-11-22',
        'last_night_avg' => 34,
        'weekly_avg' => 38,
        'status' => 'LOW',
        'baseline_balanced_low' => 44,
        'baseline_balanced_upper' => 58,
    ]);

    $daily = DailyMetric::factory()->create([
        'calendar_date' => '2026-11-22',
        'avg_stress' => 48,
    ]);

    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-21',
        'avg_stress' => 52,
    ]);

    // Very hard workout late yesterday
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-21 18:00:00'),
        'duration_seconds' => 5400.0,
        'training_load' => 280.0,
        'aerobic_te' => 5.0,
        'anaerobic_te' => 2.8,
    ]);

    $result = app(TrainingReadinessService::class)->forDate(
        selectedDate: $today,
        today: $today,
        dailyMetric: $daily,
        sleepRecord: $sleep,
        hrvRecord: $hrv,
        timezone: 'Pacific/Auckland',
    );

    expect($result['score'])->toBeLessThan(35)
        ->and($result['level'])->toBeIn(['Poor', 'Low'])
        ->and($result['factors']['sleep']['label'])->toBe('Poor')
        ->and($result['factors']['hrvStatus']['label'])->toBe('Low')
        ->and($result['factors']['recentStress']['label'])->toBe('High')
        ->and($result['factors']['recovery']['hours'])->toBeGreaterThan(30);
});
