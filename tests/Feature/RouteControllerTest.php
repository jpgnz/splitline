<?php

use App\Models\Activity;
use App\Models\ActivityRouteFingerprint;
use App\Models\ActivityStream;
use App\Models\ActivityWeather;
use App\Models\RouteCluster;
use App\Services\Routes\RouteMatchingService;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'Pacific/Auckland'));
});

/**
 * @return list<array{0: float, 1: float}>
 */
function routeTestLoop(float $centerLat = -36.8500, float $centerLng = 174.7600, float $drift = 0.0): array
{
    $coords = [];

    for ($i = 0; $i < 32; $i++) {
        $angle = ($i / 31) * 2.0 * M_PI;
        $coords[] = [
            round($centerLat + (0.0072 * sin($angle)) + $drift, 5),
            round($centerLng + (0.0072 * cos($angle)), 5),
        ];
    }

    return $coords;
}

it('lists matched routes with sport filter, search, sort options, and unfingerprinted count', function () {
    $matcher = app(RouteMatchingService::class);

    // 3x Running loop (most repeated, 5km, 60m gain)
    foreach ([1, 5, 10] as $day) {
        $run = Activity::factory()->create([
            'name' => 'Domain 5K Loop',
            'location_name' => 'Auckland',
            'sport_category' => 'running',
            'sport_discipline' => 'road',
            'started_at_local' => CarbonImmutable::parse("2026-11-{$day} 07:00:00"),
            'distance_meters' => 5000.0,
            'duration_seconds' => 1500.0 - ($day * 10.0),
            'elevation_gain' => 60.0,
        ]);
        $stream = ActivityStream::factory()->create([
            'activity_id' => $run->id,
            'polyline' => routeTestLoop(-36.85, 174.76, $day * 0.00001),
        ]);
        $matcher->matchActivity($run, $stream);
    }

    // 2x Cycling loop (longer distance, more recent, higher elevation)
    foreach ([18, 22] as $day) {
        $ride = Activity::factory()->roadBiking()->create([
            'name' => 'Tamaki Drive Loop',
            'location_name' => 'Mission Bay',
            'started_at_local' => CarbonImmutable::parse("2026-11-{$day} 08:00:00"),
            'distance_meters' => 25000.0,
            'duration_seconds' => 3000.0,
            'elevation_gain' => 420.0,
        ]);
        $stream = ActivityStream::factory()->create([
            'activity_id' => $ride->id,
            'polyline' => routeTestLoop(-36.88, 174.82, $day * 0.00001),
        ]);
        $matcher->matchActivity($ride, $stream);
    }

    // Singleton route (1 attempt — must NOT appear on /routes)
    $singleton = Activity::factory()->create([
        'name' => 'One-Off Park Run',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
        'distance_meters' => 6000.0,
    ]);
    $singletonStream = ActivityStream::factory()->create([
        'activity_id' => $singleton->id,
        'polyline' => routeTestLoop(-36.92, 174.70),
    ]);
    $matcher->matchActivity($singleton, $singletonStream);

    // Un-fingerprinted outdoor streamed activity (must count in unfingerprinted_count)
    $pending = Activity::factory()->create([
        'name' => 'Pending Scan Run',
        'distance_meters' => 5500.0,
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $pending->id,
        'polyline' => routeTestLoop(-36.95, 174.72),
    ]);

    // Default sort = count (3x Domain Loop first)
    $this->get('/routes')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Routes/Index')
            ->where('filters.sport', 'all')
            ->where('filters.sort', 'count')
            ->where('summary.route_count', 2)
            ->where('summary.total_attempts', 5)
            ->where('summary.most_repeated_count', 3)
            ->where('summary.unfingerprinted_count', 1)
            ->has('routes', 2)
            ->where('routes.0.name', 'Domain 5K Loop')
            ->where('routes.0.activity_count', 3)
            ->where('routes.1.name', 'Tamaki Drive Loop')
            ->where('routes.1.activity_count', 2),
        );

    // Sort by distance -> Tamaki Drive Loop first
    $this->get('/routes?sort=distance')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', 'distance')
            ->where('routes.0.name', 'Tamaki Drive Loop'),
        );

    // Filter by sport + search by location
    $this->get('/routes?sport=cycling&search=mission')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sport', 'cycling')
            ->where('filters.search', 'mission')
            ->has('routes', 1)
            ->where('routes.0.name', 'Tamaki Drive Loop'),
        );
});

it('renders matched route detail with progression, PR delta, and weather impact breakdown', function () {
    $matcher = app(RouteMatchingService::class);

    $coolTailwind = Activity::factory()->create([
        'name' => 'Waterfront Loop',
        'sport_category' => 'running',
        'location_name' => 'Auckland',
        'started_at_local' => CarbonImmutable::parse('2026-10-10 07:00:00'),
        'distance_meters' => 5000.0,
        'duration_seconds' => 1320.0,
        'avg_speed' => 3.788,
        'avg_hr' => 158,
    ]);
    $s1 = ActivityStream::factory()->create(['activity_id' => $coolTailwind->id, 'polyline' => routeTestLoop()]);
    ActivityWeather::factory()->create([
        'activity_id' => $coolTailwind->id,
        'temperature_c' => 11.0,
        'wind_speed_kmh' => 18.0,
        'avg_effective_wind_kmh' => 6.5,
        'tailwind_pct' => 65.0,
        'headwind_pct' => 15.0,
        'crosswind_pct' => 20.0,
    ]);

    $warmHeadwind = Activity::factory()->create([
        'name' => 'Waterfront Loop',
        'sport_category' => 'running',
        'location_name' => 'Auckland',
        'started_at_local' => CarbonImmutable::parse('2026-11-12 07:00:00'),
        'distance_meters' => 5000.0,
        'duration_seconds' => 1410.0,
        'avg_speed' => 3.546,
        'avg_hr' => 164,
    ]);
    $s2 = ActivityStream::factory()->create(['activity_id' => $warmHeadwind->id, 'polyline' => routeTestLoop(drift: 0.0001)]);
    ActivityWeather::factory()->create([
        'activity_id' => $warmHeadwind->id,
        'temperature_c' => 22.0,
        'wind_speed_kmh' => 24.0,
        'avg_effective_wind_kmh' => -8.0,
        'headwind_pct' => 70.0,
        'tailwind_pct' => 10.0,
        'crosswind_pct' => 20.0,
    ]);

    $fp1 = $matcher->matchActivity($coolTailwind, $s1);
    $matcher->matchActivity($warmHeadwind, $s2);

    $cluster = RouteCluster::query()->findOrFail($fp1?->route_cluster_id);

    $this->get("/routes/{$cluster->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Routes/Show')
            ->where('route.id', $cluster->id)
            ->where('route.activity_count', 2)
            ->where('summary.pr_activity_id', $coolTailwind->id)
            ->where('summary.pr_duration_seconds', 1320)
            ->has('polyline', 32)
            ->has('progression', 2)
            ->where('progression.0.activity_id', $coolTailwind->id)
            ->where('progression.0.is_pr', true)
            ->where('progression.0.delta_seconds', 0)
            ->where('progression.1.activity_id', $warmHeadwind->id)
            ->where('progression.1.delta_seconds', 90)
            ->where('weather_impact.sample_count', 2)
            ->where('weather_impact.wind.headwind.count', 1)
            ->where('weather_impact.wind.tailwind.count', 1)
            ->where('weather_impact.wind.headwind_vs_tailwind_delta_seconds', 90)
            ->where('weather_impact.temperature.cool.count', 1)
            ->where('weather_impact.temperature.warm.count', 1)
            ->where('weather_impact.temperature.warm_vs_cool_delta_seconds', 90),
        );
});

it('validates and persists route name overrides via PATCH /routes/{routeCluster}', function () {
    $cluster = RouteCluster::factory()->create([
        'name' => 'Auto Loop',
        'name_override' => null,
    ]);

    $this->from("/routes/{$cluster->id}")
        ->patch("/routes/{$cluster->id}", [
            'name_override' => str_repeat('a', 161),
        ])
        ->assertRedirect("/routes/{$cluster->id}")
        ->assertSessionHasErrors('name_override');

    $this->from("/routes/{$cluster->id}")
        ->patch("/routes/{$cluster->id}", [
            'name_override' => '  Sunday Harbour Loop  ',
        ])
        ->assertRedirect("/routes/{$cluster->id}")
        ->assertSessionHas('status', 'Route name updated.');

    expect($cluster->fresh()?->name_override)->toBe('Sunday Harbour Loop')
        ->and($cluster->fresh()?->displayName())->toBe('Sunday Harbour Loop');

    // Empty string clears override back to auto-detected name
    $this->from("/routes/{$cluster->id}")
        ->patch("/routes/{$cluster->id}", [
            'name_override' => '',
        ])
        ->assertRedirect("/routes/{$cluster->id}")
        ->assertSessionHas('status', 'Route name reset to auto-detected name.');

    expect($cluster->fresh()?->name_override)->toBeNull()
        ->and($cluster->fresh()?->displayName())->toBe('Auto Loop');
});

it('unlinks an activity via DELETE and removes the cluster when empty', function () {
    $matcher = app(RouteMatchingService::class);

    $a1 = Activity::factory()->create(['distance_meters' => 5000.0]);
    $s1 = ActivityStream::factory()->create(['activity_id' => $a1->id, 'polyline' => routeTestLoop()]);
    $a2 = Activity::factory()->create(['distance_meters' => 5020.0]);
    $s2 = ActivityStream::factory()->create(['activity_id' => $a2->id, 'polyline' => routeTestLoop(drift: 0.0001)]);

    $fp1 = $matcher->matchActivity($a1, $s1);
    $matcher->matchActivity($a2, $s2);
    $cluster = RouteCluster::query()->findOrFail($fp1?->route_cluster_id);

    $this->from("/routes/{$cluster->id}")
        ->delete("/routes/{$cluster->id}/activities/{$a2->id}")
        ->assertRedirect("/routes/{$cluster->id}")
        ->assertSessionHas('status', 'Activity unlinked from route.');

    expect($cluster->fresh()?->activity_count)->toBe(1)
        ->and(ActivityRouteFingerprint::query()->where('activity_id', $a2->id)->first()?->is_unlinked)->toBeTrue();

    // Unlinking the last activity deletes the cluster and redirects to /routes
    $this->from("/routes/{$cluster->id}")
        ->delete("/routes/{$cluster->id}/activities/{$a1->id}")
        ->assertRedirect('/routes')
        ->assertSessionHas('status', 'Activity unlinked and empty route removed.');

    $this->assertModelMissing($cluster);
});

it('scans unfingerprinted activities via POST /routes/scan', function () {
    $a1 = Activity::factory()->create(['distance_meters' => 5000.0]);
    ActivityStream::factory()->create(['activity_id' => $a1->id, 'polyline' => routeTestLoop()]);
    $a2 = Activity::factory()->create(['distance_meters' => 5010.0]);
    ActivityStream::factory()->create(['activity_id' => $a2->id, 'polyline' => routeTestLoop(drift: 0.0001)]);

    $this->from('/routes')
        ->post('/routes/scan')
        ->assertRedirect('/routes')
        ->assertSessionHas('status', 'Scanned 2 activity(ies); 1 matched route(s) available.');

    expect(RouteCluster::query()->matched()->count())->toBe(1);
});

it('includes matchedRoute payload on ActivityController::show only when cluster has 2+ attempts', function () {
    $matcher = app(RouteMatchingService::class);

    $fast = Activity::factory()->create([
        'name' => 'Park Tempo',
        'started_at_local' => CarbonImmutable::parse('2026-11-01 07:00:00'),
        'distance_meters' => 5000.0,
        'duration_seconds' => 1260.0,
        'avg_speed' => 3.968,
    ]);
    $s1 = ActivityStream::factory()->create(['activity_id' => $fast->id, 'polyline' => routeTestLoop()]);
    $matcher->matchActivity($fast, $s1);

    // Singleton -> matchedRoute must be null
    $this->get("/activities/{$fast->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Activities/Show')
            ->where('matchedRoute', null),
        );

    $slower = Activity::factory()->create([
        'name' => 'Park Easy',
        'started_at_local' => CarbonImmutable::parse('2026-11-10 07:00:00'),
        'distance_meters' => 5020.0,
        'duration_seconds' => 1380.0,
        'avg_speed' => 3.638,
    ]);
    $s2 = ActivityStream::factory()->create(['activity_id' => $slower->id, 'polyline' => routeTestLoop(drift: 0.0001)]);
    $matcher->matchActivity($slower, $s2);

    // Now 2 attempts -> matchedRoute populated with rank #2 of 2 and +120s delta vs PR
    $this->get("/activities/{$slower->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Activities/Show')
            ->where('matchedRoute.route.activity_count', 2)
            ->where('matchedRoute.current.rank', 2)
            ->where('matchedRoute.current.total_attempts', 2)
            ->where('matchedRoute.current.is_pr', false)
            ->where('matchedRoute.current.delta_pr_seconds', 120)
            ->has('matchedRoute.progression', 2)
            ->has('matchedRoute.attempts', 2),
        );
});
