<?php

use App\Models\Activity;
use App\Models\ActivityDerivedMetric;
use App\Models\ActivityWeather;
use App\Models\HrvRecord;
use App\Models\SleepRecord;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'Pacific/Auckland'));
});

it('renders Insights/Index with default running sport, 1y range, and full correlation props', function () {
    $run = Activity::factory()->create([
        'name' => 'Aerobic Base Run',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:30:00'),
        'distance_meters' => 10000.0,
    ]);

    ActivityDerivedMetric::factory()->for($run)->create([
        'efficiency_factor' => 1.58,
        'decoupling_pct' => 2.4,
    ]);

    ActivityWeather::factory()->for($run)->create([
        'temperature_c' => 14.0,
    ]);

    SleepRecord::factory()->create([
        'calendar_date' => '2026-11-20',
        'sleep_seconds' => 28800,
        'sleep_score' => 86,
    ]);

    HrvRecord::factory()->create([
        'calendar_date' => '2026-11-20',
        'last_night_avg' => 68,
        'status' => 'BALANCED',
    ]);

    // Next-night recovery record
    SleepRecord::factory()->create([
        'calendar_date' => '2026-11-21',
        'sleep_score' => 84,
    ]);

    $this->get('/insights')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Insights/Index')
            ->where('has_data', true)
            ->where('filters.sport', 'running')
            ->where('filters.range', '1y')
            ->where('dateWindow.range', '1y')
            ->where('dateWindow.end', '2026-11-26')
            ->where('today', '2026-11-26')
            ->has('available_sports')
            ->has('summary', fn (Assert $summary) => $summary
                ->where('total_activities', 1)
                ->where('with_derived_metrics', 1)
                ->where('with_sleep', 1)
                ->where('with_hrv', 1)
                ->where('with_weather', 1)
                ->where('best_sleep_bucket', null)
                ->where('optimal_temp_bucket', null)
                ->where('peak_time_of_day', null),
            )
            ->has('sleep_vs_performance', fn (Assert $sleep) => $sleep
                ->where('n', 1)
                ->has('duration_buckets', 4)
                ->has('score_buckets', 4)
                ->has('scatter', 1)
                ->has('correlations.score_vs_ef')
                ->has('correlations.hours_vs_decoupling')
                ->has('correlations.hours_vs_ef')
                ->has('takeaway'),
            )
            ->has('hrv_vs_performance', fn (Assert $hrv) => $hrv
                ->where('n', 1)
                ->has('status_buckets', 4)
                ->has('baseline_buckets', 3)
                ->has('scatter', 1)
                ->has('correlations')
                ->has('takeaway'),
            )
            ->has('weather_impact', fn (Assert $weather) => $weather
                ->where('n', 1)
                ->has('wind_n')
                ->has('temperature_buckets', 5)
                ->has('wind_buckets', 3)
                ->has('scatter', 1)
                ->has('correlations')
                ->has('takeaway'),
            )
            ->has('workout_to_recovery', fn (Assert $recovery) => $recovery
                ->where('n', 1)
                ->has('by_time_of_day', 3)
                ->has('by_intensity', 3)
                ->has('matrix', 9)
                ->has('correlations')
                ->has('takeaway'),
            )
            ->has('schedule_patterns', fn (Assert $schedule) => $schedule
                ->where('n', 1)
                ->has('by_day_of_week', 7)
                ->has('by_time_of_day', 5)
                ->has('takeaway'),
            ),
        );
});

it('applies sport, range, and custom date bounds query parameters with graceful invalid fallbacks', function () {
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-18 07:00:00'),
    ]);

    Activity::factory()->roadBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-20 08:00:00'),
    ]);

    $this->get('/insights?sport=all&range=custom&from=2026-11-15&to=2026-11-26')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Insights/Index')
            ->where('filters.sport', 'all')
            ->where('filters.range', 'custom')
            ->where('dateWindow.from', '2026-11-15')
            ->where('dateWindow.to', '2026-11-26')
            ->where('summary.total_activities', 2),
        );

    $this->get('/insights?sport=road_cycling&range=180d')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Insights/Index')
            ->where('filters.sport', 'road_cycling')
            ->where('filters.range', '180d')
            ->where('dateWindow.range', '180d')
            ->where('summary.total_activities', 1),
        );

    $this->get('/insights?sport=not_a_real_sport&range=bad_range')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Insights/Index')
            ->where('filters.sport', 'running')
            ->where('filters.range', '1y')
            ->where('summary.total_activities', 1),
        );
});
