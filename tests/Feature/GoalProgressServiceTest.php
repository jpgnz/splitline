<?php

use App\Models\Activity;
use App\Models\Goal;
use App\Services\Goals\GoalProgressService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->referenceDate = CarbonImmutable::parse('2026-11-18 12:00:00', 'Pacific/Auckland'); // Wednesday
});

it('evaluates weekly, monthly, and yearly goals with exact period boundaries and pro-rated pace', function () {
    $service = app(GoalProgressService::class);

    // Weekly running goal: Mon 16 Nov -> Sun 22 Nov 2026. Reference Wed 18 Nov = day 3 of 7.
    // Expected = 70 * 3/7 = 30.0 km, expected_pct = 42.9%.
    $weeklyGoal = Goal::factory()->weekly()->create([
        'title' => 'Weekly Run Volume',
        'sport' => 'running',
        'metric' => 'distance_km',
        'target_value' => 70.0,
    ]);

    // Inside week: 22 km + 18 km = 40.0 km (ahead by +10.0 km)
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-16 06:30:00'),
        'distance_meters' => 22000.0,
    ]);
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-18 07:00:00'),
        'distance_meters' => 18000.0,
    ]);
    // Outside week (previous Sunday) - should count toward monthly & yearly, NOT weekly
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-15 08:00:00'),
        'distance_meters' => 10000.0,
    ]);
    // Outside month (October) - should count toward yearly, NOT monthly or weekly
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2026-10-10 08:00:00'),
        'distance_meters' => 50000.0,
    ]);

    $weekly = $service->evaluate($weeklyGoal, $this->referenceDate);

    expect($weekly['current_value'])->toBe(40.0)
        ->and($weekly['target_value'])->toBe(70.0)
        ->and($weekly['remaining_value'])->toBe(30.0)
        ->and($weekly['expected_value'])->toBe(30.0)
        ->and($weekly['expected_pct'])->toBe(42.9)
        ->and($weekly['delta'])->toBe(10.0)
        ->and($weekly['pct'])->toBe(57.1)
        ->and($weekly['unit'])->toBe('km')
        ->and($weekly['pace_status'])->toBe('ahead')
        ->and($weekly['pace_label'])->toBe('On track · +10 km ahead')
        ->and($weekly['days_remaining'])->toBe(4)
        ->and($weekly['period_label'])->toBe('16–22 Nov');

    // Monthly goal: Nov 1 -> Nov 30 (30 days). Day 18/30 = 60.0% -> expected 120 km.
    // Current in Nov = 22 + 18 + 10 = 50.0 km -> delta = -70.0 km (behind).
    $monthlyGoal = Goal::factory()->monthly()->create([
        'title' => 'Monthly Run Distance',
        'sport' => 'running',
        'metric' => 'distance_km',
        'target_value' => 200.0,
    ]);

    $monthly = $service->evaluate($monthlyGoal, $this->referenceDate);

    expect($monthly['current_value'])->toBe(50.0)
        ->and($monthly['expected_pct'])->toBe(60.0)
        ->and($monthly['expected_value'])->toBe(120.0)
        ->and($monthly['delta'])->toBe(-70.0)
        ->and($monthly['pace_status'])->toBe('behind')
        ->and($monthly['pace_label'])->toBe('Behind · 70 km behind')
        ->and($monthly['days_remaining'])->toBe(12)
        ->and($monthly['period_label'])->toBe('November 2026');

    // Yearly goal (completed): total in 2026 = 100 km vs target 80 km -> completed 125%
    $yearlyGoal = Goal::factory()->yearly(2026)->create([
        'title' => '2026 Running Base',
        'sport' => 'running',
        'metric' => 'distance_km',
        'target_value' => 80.0,
    ]);

    $yearly = $service->evaluate($yearlyGoal, $this->referenceDate);

    expect($yearly['current_value'])->toBe(100.0)
        ->and($yearly['remaining_value'])->toBe(0.0)
        ->and($yearly['pct'])->toBe(125.0)
        ->and($yearly['pace_status'])->toBe('completed')
        ->and($yearly['pace_label'])->toBe('Completed · 125%')
        ->and($yearly['period_label'])->toBe('2026');

    // Past & future yearly goals clamp elapsed days properly
    $futureGoal = Goal::factory()->yearly(2027)->create([
        'sport' => 'running',
        'metric' => 'distance_km',
        'target_value' => 1000.0,
    ]);
    $future = $service->evaluate($futureGoal, $this->referenceDate);

    expect($future['expected_pct'])->toBe(0.0)
        ->and($future['expected_value'])->toBe(0.0)
        ->and($future['days_remaining'])->toBe(365);

    $pastGoal = Goal::factory()->yearly(2025)->create([
        'sport' => 'running',
        'metric' => 'distance_km',
        'target_value' => 500.0,
    ]);
    $past = $service->evaluate($pastGoal, $this->referenceDate);

    expect($past['expected_pct'])->toBe(100.0)
        ->and($past['expected_value'])->toBe(500.0)
        ->and($past['days_remaining'])->toBe(0);
});

it('evaluates elevation, duration, activity_count metrics and on_track tolerance', function () {
    $service = app(GoalProgressService::class);

    // 2 activities inside Mon 16 Nov -> Sun 22 Nov
    Activity::factory()->mountainBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-16 09:00:00'),
        'elevation_gain' => 845.4,
        'duration_seconds' => 5400.0, // 1.5h
    ]);
    Activity::factory()->mountainBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-17 09:00:00'),
        'elevation_gain' => 654.8,
        'duration_seconds' => 3600.0, // 1.0h
    ]);

    $elevationGoal = Goal::factory()->weekly()->create([
        'sport' => 'mtb_biking',
        'metric' => 'elevation_m',
        'target_value' => 3000.0,
    ]);
    $durationGoal = Goal::factory()->weekly()->create([
        'sport' => 'all',
        'metric' => 'duration_hours',
        'target_value' => 6.0, // Day 3/7 expected = 2.6h; current = 2.5h -> delta -0.1 >= -0.3 (5%) -> on_track
    ]);
    $countGoal = Goal::factory()->weekly()->create([
        'sport' => 'all',
        'metric' => 'activity_count',
        'target_value' => 5.0,
    ]);

    $elevation = $service->evaluate($elevationGoal, $this->referenceDate);
    $duration = $service->evaluate($durationGoal, $this->referenceDate);
    $count = $service->evaluate($countGoal, $this->referenceDate);

    expect($elevation['current_value'])->toBe(1500.0)
        ->and($elevation['unit'])->toBe('m')
        ->and($duration['current_value'])->toBe(2.5)
        ->and($duration['unit'])->toBe('h')
        ->and($duration['pace_status'])->toBe('on_track')
        ->and($duration['pace_label'])->toBe('On pace')
        ->and($count['current_value'])->toBe(2.0)
        ->and($count['unit'])->toBe('sessions');
});

it('respects effective biking discipline overrides when filtering road, mtb, and biking goals', function () {
    $service = app(GoalProgressService::class);

    // Road ride: 40 km
    Activity::factory()->roadBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-16 07:00:00'),
        'distance_meters' => 40000.0,
    ]);
    // Generic cycling overridden to MTB: 25 km
    Activity::factory()->otherBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-17 07:00:00'),
        'discipline_override' => 'mtb',
        'distance_meters' => 25000.0,
    ]);
    // MTB ride overridden to Road: 30 km
    Activity::factory()->mountainBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-18 07:00:00'),
        'discipline_override' => 'road',
        'distance_meters' => 30000.0,
    ]);
    // Run (should not count in biking goals): 10 km
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-18 08:00:00'),
        'distance_meters' => 10000.0,
    ]);

    $roadGoal = Goal::factory()->weekly()->create(['sport' => 'road_biking', 'metric' => 'distance_km', 'target_value' => 100.0]);
    $mtbGoal = Goal::factory()->weekly()->create(['sport' => 'mtb_biking', 'metric' => 'distance_km', 'target_value' => 100.0]);
    $bikingGoal = Goal::factory()->weekly()->create(['sport' => 'biking', 'metric' => 'distance_km', 'target_value' => 100.0]);
    $allGoal = Goal::factory()->weekly()->create(['sport' => 'all', 'metric' => 'distance_km', 'target_value' => 150.0]);

    expect($service->evaluate($roadGoal, $this->referenceDate)['current_value'])->toBe(70.0)
        ->and($service->evaluate($mtbGoal, $this->referenceDate)['current_value'])->toBe(25.0)
        ->and($service->evaluate($bikingGoal, $this->referenceDate)['current_value'])->toBe(95.0)
        ->and($service->evaluate($allGoal, $this->referenceDate)['current_value'])->toBe(105.0);
});

it('orders active goals by period then id and separates archived goals in dashboard data', function () {
    $service = app(GoalProgressService::class);

    $yearly = Goal::factory()->yearly(2026)->create(['title' => 'Yearly Goal', 'sport' => 'running']);
    $monthly = Goal::factory()->monthly()->create(['title' => 'Monthly Goal', 'sport' => 'road_biking']);
    $weekly = Goal::factory()->weekly()->create(['title' => 'Weekly Goal', 'sport' => 'running']);
    $archived = Goal::factory()->weekly()->archived()->create(['title' => 'Archived Goal', 'sport' => 'running']);

    $active = $service->activeGoals(null, null, $this->referenceDate);
    expect(array_column($active, 'id'))->toBe([$weekly->id, $monthly->id, $yearly->id]);

    $runningOnly = $service->activeGoals('running', null, $this->referenceDate);
    expect(array_column($runningOnly, 'id'))->toBe([$weekly->id, $yearly->id]);

    $monthlyOnly = $service->activeGoals(null, 'monthly', $this->referenceDate);
    expect(array_column($monthlyOnly, 'id'))->toBe([$monthly->id]);

    $dashboard = $service->dashboardData($this->referenceDate);
    expect(array_column($dashboard['active'], 'id'))->toBe([$weekly->id, $monthly->id, $yearly->id])
        ->and(array_column($dashboard['archived'], 'id'))->toBe([$archived->id]);
});

it('computes current and longest daily and weekly streaks overall and per sport', function () {
    $service = app(GoalProgressService::class);
    $today = CarbonImmutable::parse('2026-11-21 10:00:00', 'Pacific/Auckland'); // Saturday

    // Historical 5-day daily streak (Oct 1 -> Oct 5)
    foreach ([1, 2, 3, 4, 5] as $day) {
        Activity::factory()->create([
            'started_at_local' => CarbonImmutable::parse(sprintf('2026-10-%02d 07:00:00', $day)),
        ]);
    }

    // Current 3-day daily streak ending yesterday (Nov 18, 19, 20) — still active!
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-18 07:00:00'),
    ]);
    Activity::factory()->roadBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-19 07:00:00'),
    ]);
    Activity::factory()->otherBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
        'discipline_override' => 'mtb',
    ]);

    // Previous 3 consecutive weeks before this week:
    // This week (Mon Nov 16): has running, road, mtb
    // Last week (Mon Nov 9): has running, road
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-10 07:00:00'),
    ]);
    Activity::factory()->roadBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-12 07:00:00'),
    ]);
    // Two weeks ago (Mon Nov 2): has running only
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-04 07:00:00'),
    ]);
    // Three weeks ago (Mon Oct 26): has mtb only (overall weekly streak = 4 weeks: Oct 26, Nov 2, Nov 9, Nov 16)
    Activity::factory()->mountainBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-10-28 07:00:00'),
    ]);

    // Older 2-week MTB streak (Sep 14 & Sep 21) -> longest mtb weekly = 2, current mtb weekly = 1
    Activity::factory()->mountainBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-09-15 07:00:00'),
    ]);
    Activity::factory()->mountainBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-09-22 07:00:00'),
    ]);

    $streaks = $service->streaks($today);

    expect($streaks['current_daily_streak'])->toBe(3)
        ->and($streaks['longest_daily_streak'])->toBe(5)
        ->and($streaks['current_weekly_streak'])->toBe(4)
        ->and($streaks['longest_weekly_streak'])->toBe(4)
        ->and($streaks['by_sport']['running'])->toBe([
            'current_weekly_streak' => 3,
            'longest_weekly_streak' => 3,
        ])
        ->and($streaks['by_sport']['road_biking'])->toBe([
            'current_weekly_streak' => 2,
            'longest_weekly_streak' => 2,
        ])
        ->and($streaks['by_sport']['mtb_biking'])->toBe([
            'current_weekly_streak' => 1,
            'longest_weekly_streak' => 2,
        ]);
});
