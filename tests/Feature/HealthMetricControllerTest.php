<?php

use App\Models\DailyMetric;
use App\Models\FitnessAgeRecord;
use App\Models\HrvRecord;
use App\Models\SleepRecord;
use App\Models\Vo2MaxRecord;
use App\Models\WeightMeasurement;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'Pacific/Auckland'));
});

it('renders the health metric drilldown page for every supported metric', function (
    string $metric,
    string $accent,
    string $chartType,
    string $unit,
) {
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-21',
        'hydration_ml' => 2200,
    ]);
    SleepRecord::factory()->create(['calendar_date' => '2026-11-21']);
    HrvRecord::factory()->create(['calendar_date' => '2026-11-21']);
    WeightMeasurement::factory()->create(['calendar_date' => '2026-11-21']);
    Vo2MaxRecord::factory()->create(['calendar_date' => '2026-11-21', 'sport' => 'running']);
    FitnessAgeRecord::factory()->create(['calendar_date' => '2026-11-21']);

    $this->get("/health/{$metric}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Health/Show')
            ->where('metric', $metric)
            ->where('accent', $accent)
            ->where('chartType', $chartType)
            ->where('unit', $unit)
            ->where('range', '28d')
            ->has('summary', fn (Assert $summary) => $summary
                ->has('latest')
                ->has('average')
                ->has('min')
                ->has('max')
                ->has('extraLabel')
                ->has('extraValue'),
            )
            ->has('points', 1)
            ->has('columns')
            ->has('rows', 1),
        );
})->with([
    'body-battery' => ['body-battery', 'sky', 'range-bar', 'pts'],
    'sleep' => ['sleep', 'indigo', 'sleep-stages', 'h'],
    'hrv' => ['hrv', 'emerald', 'hrv-band', 'ms'],
    'steps' => ['steps', 'sky', 'bar-goal', 'steps'],
    'heart-rate' => ['heart-rate', 'rose', 'hr-range', 'bpm'],
    'stress' => ['stress', 'amber', 'stress-breakdown', 'score'],
    'intensity-minutes' => ['intensity-minutes', 'amber', 'intensity-stacked', 'min'],
    'weight' => ['weight', 'emerald', 'weight-comp', 'kg'],
    'vo2-max' => ['vo2-max', 'indigo', 'vo2-dual', 'mL/kg/min'],
    'fitness-age' => ['fitness-age', 'violet', 'fitness-age', 'yrs'],
    'calories' => ['calories', 'orange', 'calories-stacked', 'kcal'],
    'floors' => ['floors', 'teal', 'bar-goal', 'floors'],
    'respiration-spo2' => ['respiration-spo2', 'teal', 'dual-line', 'brpm / %'],
    'hydration' => ['hydration', 'sky', 'bar', 'mL'],
]);

it('switches date ranges accurately between 7d, 28d, and all', function () {
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-21',
        'steps' => 12000,
        'step_goal' => 10000,
    ]);

    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-05',
        'steps' => 8000,
        'step_goal' => 10000,
    ]);

    DailyMetric::factory()->create([
        'calendar_date' => '2026-07-15',
        'steps' => 15000,
        'step_goal' => 10000,
    ]);

    $this->get('/health/steps?range=7d')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Health/Show')
            ->where('range', '7d')
            ->where('startDate', '2026-11-15')
            ->where('endDate', '2026-11-21')
            ->where('summary.latest', '12,000')
            ->where('summary.extraValue', '1/1 (100%)')
            ->has('points', 1)
            ->has('rows', 1),
        );

    $this->get('/health/steps?range=28d')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Health/Show')
            ->where('range', '28d')
            ->where('startDate', '2026-10-25')
            ->where('endDate', '2026-11-21')
            ->where('summary.average', '10,000')
            ->where('summary.min', '8,000')
            ->where('summary.max', '12,000')
            ->has('points', 2)
            ->where('rows.0.date', '2026-11-21')
            ->where('rows.1.date', '2026-11-05'),
        );

    $this->get('/health/steps?range=all')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Health/Show')
            ->where('range', 'all')
            ->where('startDate', null)
            ->where('endDate', '2026-11-21')
            ->where('summary.max', '15,000')
            ->has('points', 3)
            ->has('rows', 3),
        );
});

it('returns 404 for unknown health metric keys', function () {
    $this->get('/health/does-not-exist')->assertNotFound();
});

it('supports stepped bounded windows and custom date ranges on health metrics', function () {
    DailyMetric::factory()->create([
        'calendar_date' => '2026-10-10',
        'steps' => 9500,
        'step_goal' => 10000,
        'moderate_intensity_minutes' => 30,
        'vigorous_intensity_minutes' => 10,
    ]);

    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-20',
        'steps' => 12500,
        'step_goal' => 10000,
        'moderate_intensity_minutes' => 25,
        'vigorous_intensity_minutes' => 15,
    ]);

    $this->get('/health/steps?range=28d&end=2026-10-24')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Health/Show')
            ->where('range', '28d')
            ->where('startDate', '2026-09-27')
            ->where('endDate', '2026-10-24')
            ->where('dateWindow.isLatest', false)
            ->where('dateWindow.nextEnd', '2026-11-21')
            ->has('points', 1)
            ->where('summary.latest', '9,500'),
        );

    $this->get('/health/intensity-minutes?range=custom&from=2026-10-01&to=2026-10-31')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Health/Show')
            ->where('range', 'custom')
            ->where('startDate', '2026-10-01')
            ->where('endDate', '2026-10-31')
            ->where('dateWindow.from', '2026-10-01')
            ->where('dateWindow.to', '2026-10-31')
            ->has('points', 1)
            ->where('points.0.granularity', 'daily'),
        );
});
