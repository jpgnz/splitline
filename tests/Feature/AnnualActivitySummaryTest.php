<?php

use App\Models\Activity;
use App\Services\Activities\AnnualActivitySummaryService;
use App\Support\SportRegistry;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-08-15 10:00:00', 'Pacific/Auckland'));
});

it('aggregates daily activity metrics across multiple years and excludes future dates', function () {
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2025-05-10 07:00:00'),
        'distance_meters' => 10000.0,
        'elevation_gain' => 120.0,
        'duration_seconds' => 3000.0,
        'calories' => 620,
        'tss' => 70.0,
        'training_load' => 50.0,
    ]);

    $first = Activity::factory()->create([
        'name' => 'Morning Easy Run',
        'sport_category' => 'running',
        'sport_discipline' => 'road',
        'started_at_local' => CarbonImmutable::parse('2026-08-15 06:30:00'),
        'distance_meters' => 5000.0,
        'elevation_gain' => 60.0,
        'duration_seconds' => 1500.0,
        'calories' => 310,
        'tss' => null,
        'training_load' => 45.5,
    ]);

    $second = Activity::factory()->create([
        'name' => 'Evening Strides',
        'sport_category' => 'running',
        'sport_discipline' => 'track',
        'started_at_local' => CarbonImmutable::parse('2026-08-15 17:30:00'),
        'distance_meters' => 3000.0,
        'elevation_gain' => 40.0,
        'duration_seconds' => 900.0,
        'calories' => 190,
        'tss' => 24.5,
        'training_load' => 20.0,
    ]);

    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2026-08-16 08:00:00'),
        'distance_meters' => 20000.0,
        'calories' => 900,
    ]);

    $summary = app(AnnualActivitySummaryService::class)->forQuery(
        Activity::query(),
        CarbonImmutable::parse('2026-08-15', 'Pacific/Auckland'),
    );

    expect($summary['today'])->toBe('2026-08-15')
        ->and($summary['years'])->toBe([2026, 2025])
        ->and($summary['daily'])->toHaveCount(2)
        ->and($summary['daily'][0])->toMatchArray([
            'date' => '2025-05-10',
            'activities' => 1,
            'distance_meters' => 10000.0,
            'elevation_gain' => 120.0,
            'duration_seconds' => 3000.0,
            'calories' => 620,
            'training_load' => 70.0,
        ])
        ->and($summary['daily'][1])->toMatchArray([
            'date' => '2026-08-15',
            'activities' => 2,
            'distance_meters' => 8000.0,
            'elevation_gain' => 100.0,
            'duration_seconds' => 2400.0,
            'calories' => 500,
            'training_load' => 70.0,
        ])
        ->and($summary['daily'][1]['items'])->toBe([
            [
                'id' => $first->id,
                'name' => 'Morning Easy Run',
                'sport_category' => 'running',
                'effective_discipline' => 'road',
                'distance_meters' => 5000.0,
                'duration_seconds' => 1500.0,
            ],
            [
                'id' => $second->id,
                'name' => 'Evening Strides',
                'sport_category' => 'running',
                'effective_discipline' => 'track',
                'distance_meters' => 3000.0,
                'duration_seconds' => 900.0,
            ],
        ]);
});

it('exposes all-sport annual activity summary on the health summary dashboard', function () {
    Activity::factory()->create([
        'sport_category' => 'running',
        'sport_discipline' => 'road',
        'started_at_local' => CarbonImmutable::parse('2026-07-01 07:00:00'),
        'duration_seconds' => 2400.0,
        'calories' => 450,
    ]);

    Activity::factory()->create([
        'sport_category' => 'cardio',
        'sport_discipline' => null,
        'started_at_local' => CarbonImmutable::parse('2026-07-01 18:00:00'),
        'duration_seconds' => 1800.0,
        'calories' => 300,
    ]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('annualActivitySummary.today', '2026-08-15')
            ->where('annualActivitySummary.years', [2026])
            ->has('annualActivitySummary.daily', 1)
            ->where('annualActivitySummary.daily.0.activities', 2)
            ->where('annualActivitySummary.daily.0.duration_seconds', 4200)
            ->where('annualActivitySummary.daily.0.calories', 750),
        );
});

it('scopes annual summary to running, cycling, and selected sub-disciplines', function () {
    Activity::factory()->roadBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-06-10 07:00:00'),
        'distance_meters' => 50000.0,
        'elevation_gain' => 600.0,
        'duration_seconds' => 5400.0,
        'calories' => 1200,
    ]);

    Activity::factory()->mountainBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-06-12 09:00:00'),
        'distance_meters' => 22000.0,
        'elevation_gain' => 900.0,
        'duration_seconds' => 4800.0,
        'calories' => 980,
    ]);

    Activity::factory()->create([
        'sport_category' => 'running',
        'sport_discipline' => 'road',
        'started_at_local' => CarbonImmutable::parse('2026-06-14 07:00:00'),
        'distance_meters' => 10000.0,
        'elevation_gain' => 80.0,
        'duration_seconds' => 2800.0,
        'calories' => 640,
    ]);

    Activity::factory()->create([
        'sport_category' => 'hiking',
        'sport_discipline' => null,
        'started_at_local' => CarbonImmutable::parse('2026-06-15 08:00:00'),
        'distance_meters' => 12000.0,
        'elevation_gain' => 700.0,
        'duration_seconds' => 9000.0,
        'calories' => 1100,
    ]);

    app(SportRegistry::class)->flushMemo();

    // Cycling family overview includes road + mtb (2 days), excludes running & hiking
    $this->get('/cycling')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sports/Overview')
            ->where('annualSummary.years', [2026])
            ->has('annualSummary.daily', 2),
        );

    // Cycling sub-discipline filters strictly to MTB
    $this->get('/cycling/mtb')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sports/Endurance')
            ->where('annualSummary.years', [2026])
            ->has('annualSummary.daily', 1)
            ->where('annualSummary.daily.0.date', '2026-06-12')
            ->where('annualSummary.daily.0.distance_meters', 22000)
            ->where('annualSummary.daily.0.elevation_gain', 900),
        );

    // Running unified hub includes only running
    $this->get('/running')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sports/Endurance')
            ->where('annualSummary.years', [2026])
            ->has('annualSummary.daily', 1)
            ->where('annualSummary.daily.0.date', '2026-06-14')
            ->where('annualSummary.daily.0.distance_meters', 10000),
        );

    // Non-running/cycling endurance hub omits annualSummary
    $this->get('/sports/hiking')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sports/Endurance')
            ->where('annualSummary', null),
        );
});
