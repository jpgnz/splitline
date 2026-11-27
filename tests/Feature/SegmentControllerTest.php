<?php

use App\Jobs\MatchSegmentBackfillJob;
use App\Jobs\ScanActivitySegmentsJob;
use App\Models\Activity;
use App\Models\ActivityStream;
use App\Models\ActivityWeather;
use App\Models\Segment;
use App\Models\SegmentEffort;
use App\Support\AppSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config([
        'app.display_timezone' => 'Pacific/Auckland',
        'services.nominatim.enabled' => false,
        'services.nominatim.auto_sync' => false,
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'Pacific/Auckland'));
});

/**
 * @return list<array{time: float, distance: float, altitude: float, speed: float, hr: int, power: int, lat: float, lng: float}>
 */
function controllerTestSeries(int $count = 40, float $stepMeters = 25.0): array
{
    $series = [];

    for ($i = 0; $i < $count; $i++) {
        $dist = $i * $stepMeters;
        $series[] = [
            'time' => $i * 5.0,
            'distance' => $dist,
            'altitude' => 40.0 + ($dist * 0.05),
            'speed' => 5.0,
            'hr' => 160,
            'power' => 260,
            'lat' => round(-36.8800 + ($dist / 111320.0), 6),
            'lng' => 174.7600,
        ];
    }

    return $series;
}

it('lists segments with kind, sport, climb category, search, and sort filters', function () {
    $hcClimb = Segment::factory()->climb('hc')->starred()->create([
        'name' => 'Waitakere Summit Climb',
        'place_label' => 'Scenic Drive · Auckland',
        'sport_category' => 'biking',
        'distance_meters' => 10500.0,
        'elevation_gain' => 860.0,
        'avg_grade_pct' => 8.2,
        'fiets_score' => 7.04,
        'effort_count' => 3,
        'best_elapsed_seconds' => 2400.0,
    ]);

    $cat4Climb = Segment::factory()->climb('cat4')->create([
        'name' => 'Domain Hill Climb',
        'place_label' => 'Auckland Domain · Auckland',
        'sport_category' => 'running',
        'distance_meters' => 1200.0,
        'elevation_gain' => 85.0,
        'avg_grade_pct' => 7.1,
        'fiets_score' => 0.60,
        'effort_count' => 8,
        'best_elapsed_seconds' => 330.0,
    ]);

    $custom = Segment::factory()->custom()->create([
        'name' => 'Waterfront Sprint',
        'place_label' => 'Tamaki Drive · Auckland',
        'sport_category' => 'biking',
        'distance_meters' => 800.0,
        'elevation_gain' => 6.0,
        'effort_count' => 5,
        'best_elapsed_seconds' => 75.0,
    ]);

    // Default sort = efforts (Domain Hill Climb 8x first)
    $this->get('/segments')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Segments/Index')
            ->where('filters.kind', 'all')
            ->where('summary.total_segments', 3)
            ->where('summary.climbs_count', 2)
            ->where('summary.custom_count', 1)
            ->where('summary.starred_count', 1)
            ->has('segments', 3)
            ->where('segments.0.id', $cat4Climb->id)
            ->where('segments.1.id', $custom->id)
            ->where('segments.2.id', $hcClimb->id),
        );

    // Filter by kind=starred
    $this->get('/segments?kind=starred')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('segments', 1)
            ->where('segments.0.id', $hcClimb->id),
        );

    // Filter by category=hc + sort=climb_score
    $this->get('/segments?kind=climb&category=hc&sort=climb_score')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('segments', 1)
            ->where('segments.0.id', $hcClimb->id),
        );

    // Search by place_label
    $this->get('/segments?search=tamaki')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('segments', 1)
            ->where('segments.0.id', $custom->id),
        );
});

it('renders segment detail with personal leaderboard, year filter, weather, and elevation profile', function () {
    $act2025 = Activity::factory()->roadBiking()->create([
        'name' => 'Autumn Climb 2025',
        'started_at_local' => CarbonImmutable::parse('2025-05-10 08:00:00'),
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $act2025->id,
        'series' => controllerTestSeries(),
    ]);
    ActivityWeather::factory()->create([
        'activity_id' => $act2025->id,
        'temperature_c' => 14.5,
        'wind_speed_kmh' => 12.0,
        'avg_effective_wind_kmh' => 2.5,
    ]);

    $act2026 = Activity::factory()->roadBiking()->create([
        'name' => 'Spring Climb PR 2026',
        'started_at_local' => CarbonImmutable::parse('2026-11-10 08:00:00'),
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $act2026->id,
        'series' => controllerTestSeries(),
    ]);

    $segment = Segment::factory()->climb('cat4')->create([
        'name' => 'Scenic Drive Climb',
        'source_activity_id' => $act2025->id,
        'effort_count' => 2,
        'best_elapsed_seconds' => 300.0,
    ]);

    $effort2025 = SegmentEffort::factory()->create([
        'segment_id' => $segment->id,
        'activity_id' => $act2025->id,
        'started_at_local' => CarbonImmutable::parse('2025-05-10 08:05:00'),
        'start_index' => 0,
        'end_index' => 39,
        'elapsed_seconds' => 340.0,
        'vam' => 980.0,
        'avg_power' => 250.0,
        'w_per_kg' => 3.57,
        'rank' => 2,
        'is_pr' => false,
    ]);

    $effort2026 = SegmentEffort::factory()->create([
        'segment_id' => $segment->id,
        'activity_id' => $act2026->id,
        'started_at_local' => CarbonImmutable::parse('2026-11-10 08:05:00'),
        'start_index' => 0,
        'end_index' => 39,
        'elapsed_seconds' => 300.0,
        'vam' => 1110.0,
        'avg_power' => 285.0,
        'w_per_kg' => 4.07,
        'rank' => 1,
        'is_pr' => true,
    ]);

    $segment->update(['best_effort_id' => $effort2026->id]);

    // All-Time view
    $this->get("/segments/{$segment->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Segments/Show')
            ->where('segment.name', 'Scenic Drive Climb')
            ->where('summary.effort_count', 2)
            ->where('summary.pr_elapsed_seconds', 300)
            ->where('available_years', [2026, 2025])
            ->where('selected_year', null)
            ->has('efforts', 2)
            ->where('efforts.0.id', $effort2026->id)
            ->where('efforts.0.delta_pr_seconds', 0)
            ->where('efforts.1.id', $effort2025->id)
            ->where('efforts.1.delta_pr_seconds', 40)
            ->where('efforts.1.weather.wind_label', 'Tailwind')
            ->has('elevation_profile', 40),
        );

    // Filtered by year=2025
    $this->get("/segments/{$segment->id}?year=2025")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_year', 2025)
            ->where('summary.effort_count', 1)
            ->where('summary.pr_elapsed_seconds', 340)
            ->has('efforts', 1)
            ->where('efforts.0.id', $effort2025->id)
            ->where('efforts.0.display_rank', 1),
        );
});

it('creates a custom segment via POST /segments, updates name and star independently, deletes it, and exposes segmentEfforts on activity detail', function () {
    $activity = Activity::factory()->roadBiking()->create([
        'name' => 'Coastal Ride',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:30:00'),
        'distance_meters' => 1000.0,
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => controllerTestSeries(40, 25.0),
    ]);

    // Validation error when slice is too short (< 100m / < 5 points)
    $this->post('/segments', [
        'activity_id' => $activity->id,
        'start_index' => 5,
        'end_index' => 7,
        'name' => 'Too Short',
    ])->assertSessionHasErrors('start_index');

    // Valid custom segment creation
    $response = $this->post('/segments', [
        'activity_id' => $activity->id,
        'start_index' => 4,
        'end_index' => 24,
        'name' => 'Coastal Kicker',
    ]);

    $segment = Segment::query()->firstOrFail();

    $response->assertRedirect(route('segments.show', $segment));
    expect($segment->kind)->toBe(Segment::KIND_CUSTOM)
        ->and($segment->displayName())->toBe('Coastal Kicker')
        ->and($segment->effort_count)->toBe(1);

    // Activity detail includes segmentEfforts
    $this->get("/activities/{$activity->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Activities/Show')
            ->has('segmentEfforts', 1)
            ->where('segmentEfforts.0.segment_id', $segment->id)
            ->where('segmentEfforts.0.segment_name', 'Coastal Kicker')
            ->where('segmentEfforts.0.is_pr', true),
        );

    // Star without clearing name_override
    $this->patch("/segments/{$segment->id}", ['is_starred' => true])
        ->assertRedirect();

    expect($segment->fresh()?->is_starred)->toBeTrue()
        ->and($segment->fresh()?->name_override)->toBe('Coastal Kicker');

    // Rename without clearing is_starred
    $this->patch("/segments/{$segment->id}", ['name_override' => 'Coastal Kicker v2'])
        ->assertRedirect();

    expect($segment->fresh()?->is_starred)->toBeTrue()
        ->and($segment->fresh()?->displayName())->toBe('Coastal Kicker v2');

    // Delete segment
    $this->delete("/segments/{$segment->id}")
        ->assertRedirect(route('segments.index'));

    expect(Segment::query()->whereKey($segment->id)->exists())->toBeFalse()
        ->and(SegmentEffort::query()->where('segment_id', $segment->id)->exists())->toBeFalse();
});

it('scans unscanned activities synchronously via POST /segments/scan', function () {
    $activity = Activity::factory()->roadBiking()->create([
        'segments_scanned_at' => null,
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => controllerTestSeries(60, 25.0),
    ]);

    $this->post('/segments/scan')
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($activity->fresh()?->segments_scanned_at)->not->toBeNull()
        ->and(Segment::query()->climbs()->count())->toBe(1);
});

it('hides auto-climbs below configured min_efforts while always showing custom and starred segments', function () {
    $activity = Activity::factory()->roadBiking()->create();

    $hiddenClimb = Segment::factory()->climb('cat4')->create([
        'name' => 'Once-Ridden Hill',
        'effort_count' => 2,
        'detection_count' => 2,
    ]);

    $visibleClimb = Segment::factory()->climb('cat3')->create([
        'name' => 'Local Staple Climb',
        'effort_count' => 6,
        'detection_count' => 6,
    ]);

    $starredClimb = Segment::factory()->climb('cat2')->starred()->create([
        'name' => 'Holiday Epic Climb',
        'effort_count' => 1,
        'detection_count' => 1,
    ]);

    $customSegment = Segment::factory()->custom()->create([
        'name' => 'My Sprint',
        'effort_count' => 1,
    ]);

    SegmentEffort::factory()->create(['segment_id' => $hiddenClimb->id, 'activity_id' => $activity->id, 'start_index' => 1]);
    SegmentEffort::factory()->create(['segment_id' => $visibleClimb->id, 'activity_id' => $activity->id, 'start_index' => 20]);

    // Default threshold (5): hiddenClimb excluded from /segments and activity detail
    $this->get('/segments')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.min_efforts', 5)
            ->where('summary.total_segments', 3)
            ->where('summary.climbs_count', 2)
            ->has('segments', 3),
        );

    $this->get("/activities/{$activity->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('segmentEfforts', 1)
            ->where('segmentEfforts.0.segment_id', $visibleClimb->id),
        );

    // Lowering threshold to 2 immediately surfaces hiddenClimb without rescanning
    app(AppSettings::class)->set('segments_min_efforts', 2);

    $this->get('/segments')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.min_efforts', 2)
            ->where('summary.total_segments', 4)
            ->has('segments', 4),
        );
});

it('queues the climb & segment scan instead of running it in the request when many activities are pending', function () {
    Queue::fake([ScanActivitySegmentsJob::class]);

    $activities = Activity::factory()->roadBiking()->count(11)->create(['segments_scanned_at' => null]);

    foreach ($activities as $activity) {
        ActivityStream::factory()->create([
            'activity_id' => $activity->id,
            'series' => controllerTestSeries(60, 25.0),
        ]);
    }

    $this->post(route('segments.scan'))
        ->assertRedirect()
        ->assertSessionHas('status', 'Queued background climb & segment scan for 11 activities. Refresh shortly to see results.');

    Queue::assertPushed(ScanActivitySegmentsJob::class, fn (ScanActivitySegmentsJob $job): bool => ! $job->rebuild);

    expect(Activity::query()->whereNotNull('segments_scanned_at')->exists())->toBeFalse()
        ->and(Segment::query()->exists())->toBeFalse();
});

it('rejects custom segments from activities without usable outdoor telemetry', function (array $activityAttributes, bool $withStream) {
    $activity = Activity::factory()->roadBiking()->create($activityAttributes);

    if ($withStream) {
        ActivityStream::factory()->create([
            'activity_id' => $activity->id,
            'series' => controllerTestSeries(40, 25.0),
        ]);
    }

    $this->post(route('segments.store'), [
        'activity_id' => $activity->id,
        'start_index' => 4,
        'end_index' => 24,
    ])->assertSessionHasErrors('start_index');

    expect(Segment::query()->exists())->toBeFalse();
})->with([
    'indoor activity' => [['is_indoor' => true], true],
    'activity without a stream' => [[], false],
]);

it('queues the historical backfill when a new custom segment has many candidate activities', function () {
    Queue::fake([MatchSegmentBackfillJob::class]);

    $activities = Activity::factory()->roadBiking()->count(27)->create();

    foreach ($activities as $activity) {
        ActivityStream::factory()->create([
            'activity_id' => $activity->id,
            'series' => controllerTestSeries(40, 25.0),
        ]);
    }

    $source = $activities->first();

    $this->post(route('segments.store'), [
        'activity_id' => $source->id,
        'start_index' => 4,
        'end_index' => 24,
    ])->assertRedirect();

    $segment = Segment::query()->sole();

    Queue::assertPushed(
        MatchSegmentBackfillJob::class,
        fn (MatchSegmentBackfillJob $job): bool => $job->segmentId === $segment->id && $job->excludeActivityId === $source->id,
    );

    expect($segment->effort_count)->toBe(1);
});
