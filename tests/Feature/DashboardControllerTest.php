<?php

use App\Models\Activity;
use App\Models\DailyMetric;
use App\Models\HrvRecord;
use App\Models\SleepRecord;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'Pacific/Auckland'));
});

it('renders the dashboard with live health telemetry props', function () {
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-21',
        'steps' => 11250,
        'step_goal' => 10000,
        'body_battery_current' => 78,
        'body_battery_high' => 95,
    ]);

    SleepRecord::factory()->create([
        'calendar_date' => '2026-11-21',
        'sleep_seconds' => 27000,
        'sleep_score' => 83,
    ]);

    HrvRecord::factory()->create([
        'calendar_date' => '2026-11-21',
        'last_night_avg' => 63,
        'status' => 'BALANCED',
    ]);

    Activity::factory()->create([
        'name' => 'Morning Tempo',
        'started_at_local' => CarbonImmutable::parse('2026-11-21 06:30:00'),
        'training_load' => 98.5,
    ]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('date', '2026-11-21')
            ->where('dateLabel', 'Sat, 21 Nov 2026')
            ->where('isToday', true)
            ->where('nextDate', null)
            ->where('latestActivity.name', 'Morning Tempo')
            ->where('latestActivity.is_today', true)
            ->where('bodyBattery.current', 78)
            ->where('sleep.totalSeconds', 27000)
            ->where('hrv.lastNightAvg', 63)
            ->where('trainingReadiness.level', fn ($level) => is_string($level))
            ->has('weeklyActivity.days', 7)
            ->has('weeklyActivity.last4w', 28)
            ->where('training7d.activity_count', 1)
            ->where('atAGlance.steps.count', 11250)
            ->where('atAGlance.healthStatus.status', 'In Range'),
        );
});

it('supports selecting a past date via query parameter and caps future dates at today', function () {
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-18',
        'steps' => 8450,
    ]);

    $this->get('/?date=2026-11-18')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('date', '2026-11-18')
            ->where('prevDate', '2026-11-17')
            ->where('nextDate', '2026-11-19')
            ->where('isToday', false)
            ->where('atAGlance.steps.count', 8450),
        );

    $this->get('/?date=2026-12-30')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('date', '2026-11-21')
            ->where('isToday', true)
            ->where('nextDate', null),
        );
});
