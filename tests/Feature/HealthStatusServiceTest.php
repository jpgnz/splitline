<?php

use App\Models\DailyMetric;
use App\Models\HrvRecord;
use App\Services\Health\HealthStatusService;
use Carbon\CarbonImmutable;

it('returns an empty health status when no metrics are recorded', function () {
    $date = CarbonImmutable::parse('2026-11-22', 'Pacific/Auckland')->startOfDay();

    $result = app(HealthStatusService::class)->forDate($date, null, null);

    expect($result['status'])->toBeNull()
        ->and($result['checkedCount'])->toBe(0)
        ->and($result['metrics'])->toBe([]);
});

it('reports In Range when all tracked physiological metrics sit within baseline', function () {
    $date = CarbonImmutable::parse('2026-11-22', 'Pacific/Auckland')->startOfDay();

    for ($offset = 1; $offset <= 10; $offset++) {
        DailyMetric::factory()->create([
            'calendar_date' => $date->subDays($offset)->format('Y-m-d'),
            'resting_hr' => 46,
            'avg_respiration' => 13.5,
            'avg_spo2' => 95.0,
        ]);
    }

    $daily = DailyMetric::factory()->create([
        'calendar_date' => '2026-11-22',
        'resting_hr' => 47,
        'seven_day_avg_rhr' => 46,
        'avg_respiration' => 13.0,
        'avg_spo2' => 94.0,
    ]);

    $hrv = HrvRecord::factory()->create([
        'calendar_date' => '2026-11-22',
        'last_night_avg' => 52,
        'status' => 'BALANCED',
        'baseline_balanced_low' => 43,
        'baseline_balanced_upper' => 58,
    ]);

    $result = app(HealthStatusService::class)->forDate($date, $daily, $hrv);

    expect($result['status'])->toBe('In Range')
        ->and($result['tone'])->toBe('positive')
        ->and($result['inRangeCount'])->toBe(4)
        ->and($result['checkedCount'])->toBe(4)
        ->and($result['summary'])->toBe('All your metrics are in range.');
});

it('flags Attention for a single out-of-range metric and Out of Range for multiple', function () {
    $date = CarbonImmutable::parse('2026-11-22', 'Pacific/Auckland')->startOfDay();

    for ($offset = 1; $offset <= 10; $offset++) {
        DailyMetric::factory()->create([
            'calendar_date' => $date->subDays($offset)->format('Y-m-d'),
            'resting_hr' => 46,
            'avg_respiration' => 13.5,
            'avg_spo2' => 96.0,
        ]);
    }

    $dailySingle = DailyMetric::factory()->create([
        'calendar_date' => '2026-11-22',
        'resting_hr' => 47,
        'avg_respiration' => 13.5,
        'avg_spo2' => 96.0,
    ]);

    $unbalancedHrv = HrvRecord::factory()->create([
        'calendar_date' => '2026-11-22',
        'last_night_avg' => 36,
        'status' => 'UNBALANCED',
    ]);

    $single = app(HealthStatusService::class)->forDate($date, $dailySingle, $unbalancedHrv);
    expect($single['status'])->toBe('Attention')
        ->and($single['tone'])->toBe('moderate')
        ->and($single['inRangeCount'])->toBe(3)
        ->and($single['summary'])->toContain('HRV');

    $dailySingle->update(['resting_hr' => 58]);
    $multiple = app(HealthStatusService::class)->forDate($date, $dailySingle->fresh(), $unbalancedHrv);
    expect($multiple['status'])->toBe('Out of Range')
        ->and($multiple['tone'])->toBe('negative')
        ->and($multiple['inRangeCount'])->toBe(2)
        ->and($multiple['summary'])->toContain('HRV')
        ->and($multiple['summary'])->toContain('Resting HR');
});
