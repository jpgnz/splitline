<?php

use App\Jobs\GeocodeActivityLocationJob;
use App\Jobs\MatchActivitySegmentsJob;
use App\Jobs\SyncActivityStreamJob;
use App\Jobs\SyncActivityWeatherJob;
use App\Models\Activity;
use App\Models\ActivityRouteFingerprint;
use App\Models\ActivityStream;
use App\Models\ActivityWeather;
use App\Models\BestEffort;
use App\Models\GearItem;
use App\Models\RouteCluster;
use App\Models\Segment;
use App\Models\SegmentEffort;
use App\Services\Activities\ActivityExclusionService;
use App\Services\Activities\ActivityQueryService;
use App\Services\Activities\PaceDistributionService;
use App\Services\Garmin\ActivityStreamSyncService;
use App\Services\Gear\GearService;
use App\Services\Ingestion\AutoImportService;
use App\Services\Ingestion\TelemetryPersistenceService;
use App\Services\Recap\AnnualRecapService;
use App\Services\Routes\RouteMatchingService;
use App\Services\Sports\EnduranceHubService;
use App\Support\SportRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Splitline\Core\Data\NormalizedActivity;
use Splitline\ProviderFileImport\FitFileImportProvider;

beforeEach(function () {
    Storage::fake('local');
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'Pacific/Auckland'));
});

it('excludes and restores an activity via HTTP endpoints and separates it in the activities index and CSV export', function () {
    Queue::fake();

    $active = Activity::factory()->create([
        'name' => 'Good Morning Run',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
        'distance_meters' => 10000.0,
        'duration_seconds' => 3000.0,
    ]);

    $bung = Activity::factory()->create([
        'name' => 'Bung GPS File',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-21 07:00:00'),
        'distance_meters' => 95000.0,
        'duration_seconds' => 600.0,
        'segments_scanned_at' => CarbonImmutable::now('UTC'),
    ]);

    ActivityStream::factory()->create(['activity_id' => $bung->id]);

    $this->from("/activities/{$bung->id}")
        ->post("/activities/{$bung->id}/exclude")
        ->assertRedirect("/activities/{$bung->id}")
        ->assertSessionHas('status');

    expect($bung->fresh()?->isExcluded())->toBeTrue()
        ->and(Activity::query()->count())->toBe(1)
        ->and(Activity::withExcluded()->count())->toBe(2)
        ->and(Activity::onlyExcluded()->count())->toBe(1);

    // Default activities index hides excluded activity and exposes excluded_count
    $this->get('/activities')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Activities/Index')
            ->where('filters.excluded', false)
            ->where('excluded_count', 1)
            ->where('summary.count', 1)
            ->where('summary.total_distance_meters', 10000)
            ->has('activities.data', 1)
            ->where('activities.data.0.id', $active->id),
        );

    // Excluded filter shows only excluded activities
    $this->get('/activities?excluded=1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Activities/Index')
            ->where('filters.excluded', true)
            ->where('excluded_count', 1)
            ->has('activities.data', 1)
            ->where('activities.data.0.id', $bung->id)
            ->where('activities.data.0.is_excluded', true),
        );

    // Excluded activity detail page remains reachable so user can inspect and restore it
    $this->get("/activities/{$bung->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Activities/Show')
            ->where('activity.id', $bung->id)
            ->where('activity.is_excluded', true)
            ->where('prevActivity', null)
            ->where('nextActivity', null),
        );

    // CSV export omits excluded activities
    $csvResponse = $this->get('/exports/activities?format=csv');
    $csvResponse->assertOk();
    $csv = $csvResponse->streamedContent();
    expect($csv)->toContain('Good Morning Run')
        ->and($csv)->not->toContain('Bung GPS File');

    // Restore brings the activity back and queues segment re-matching
    $this->from("/activities/{$bung->id}")
        ->post("/activities/{$bung->id}/restore")
        ->assertRedirect("/activities/{$bung->id}")
        ->assertSessionHas('status', 'Activity restored to stats.');

    $restored = $bung->fresh();
    expect($restored?->isExcluded())->toBeFalse()
        ->and($restored?->segments_scanned_at)->toBeNull()
        ->and(Activity::query()->count())->toBe(2)
        ->and(Activity::onlyExcluded()->count())->toBe(0);

    Queue::assertPushed(MatchActivitySegmentsJob::class, fn (MatchActivitySegmentsJob $job): bool => $job->activityId === $bung->id);
});

it('prevents Garmin re-syncs from recreating, overwriting, or enriching an excluded activity', function () {
    Queue::fake();

    $persistence = app(TelemetryPersistenceService::class);
    $exclusions = app(ActivityExclusionService::class);

    $startedLocal = CarbonImmutable::parse('2026-11-20 07:00:00', 'Pacific/Auckland');
    $dto = new NormalizedActivity(
        provider: 'garmin_personal',
        externalId: '777001',
        name: 'Corrupt Garmin Run',
        activityTypeKey: 'running',
        sportCategory: 'running',
        sportDiscipline: 'road',
        isIndoor: false,
        startedAtLocal: $startedLocal,
        startedAtUtc: $startedLocal->setTimezone('UTC'),
        distanceMeters: 10000.0,
        durationSeconds: 2700.0,
        startLat: -36.8485,
        startLng: 174.7633,
    );

    $activity = $persistence->upsertActivity($dto, dispatchEnrichments: false);
    $exclusions->exclude($activity);

    $updatedDto = new NormalizedActivity(
        provider: 'garmin_personal',
        externalId: '777001',
        name: 'Garmin Renamed Run',
        activityTypeKey: 'running',
        sportCategory: 'running',
        sportDiscipline: 'road',
        isIndoor: false,
        startedAtLocal: $startedLocal,
        startedAtUtc: $startedLocal->setTimezone('UTC'),
        distanceMeters: 12000.0,
        durationSeconds: 2900.0,
        startLat: -36.8485,
        startLng: 174.7633,
        seededSplits: [
            ['distance_key' => '5k', 'distance_meters' => 5000.0, 'elapsed_seconds' => 900.0, 'source' => 'garmin_split'],
        ],
    );

    $resynced = $persistence->upsertActivity($updatedDto, dispatchEnrichments: true);

    expect($resynced->id)->toBe($activity->id)
        ->and($resynced->isExcluded())->toBeTrue()
        ->and($resynced->name)->toBe('Corrupt Garmin Run')
        ->and($resynced->distance_meters)->toBe(10000.0)
        ->and(Activity::withExcluded()->count())->toBe(1)
        ->and(BestEffort::query()->where('activity_id', $activity->id)->count())->toBe(0);

    Queue::assertNotPushed(SyncActivityStreamJob::class);
    Queue::assertNotPushed(SyncActivityWeatherJob::class);
    Queue::assertNotPushed(GeocodeActivityLocationJob::class);
});

it('prevents AutoImportService and import:fit from re-importing an excluded FIT file', function () {
    $importDir = storage_path('framework/testing/excluded-auto-import-'.uniqid());
    File::ensureDirectoryExists($importDir);

    try {
        $fitPath = $importDir.DIRECTORY_SEPARATOR.'bung.fit';
        $fakeFitBytes = str_repeat('F', 64);
        file_put_contents($fitPath, $fakeFitBytes);
        touch($fitPath, time() - 120);

        $sha256 = hash('sha256', $fakeFitBytes);

        Activity::factory()->excluded()->create([
            'provider' => app(FitFileImportProvider::class)->id(),
            'external_id' => $sha256,
            'garmin_activity_id' => null,
            'name' => 'Excluded Bung Upload',
        ]);

        $autoImportService = app(AutoImportService::class);
        $result = $autoImportService->scan(
            directoryOverride: $importDir,
            modeOverride: AutoImportService::MODE_KEEP,
            minFileAgeSeconds: 0,
            requireStableAcrossScans: false,
        );

        expect($result['created'])->toBe(0)
            ->and($result['skipped'])->toBe(1)
            ->and($result['failed'])->toBe(0)
            ->and(Activity::withExcluded()->count())->toBe(1);
    } finally {
        File::deleteDirectory($importDir);
    }
});

it('removes excluded activities from gear mileage, sport counts, best effort rankings, recap, routes, and segments', function () {
    $exclusions = app(ActivityExclusionService::class);

    $good = Activity::factory()->create([
        'name' => 'Legit 10K',
        'sport_category' => 'running',
        'sport_discipline' => 'road',
        'started_at_local' => CarbonImmutable::parse('2026-10-10 07:00:00'),
        'distance_meters' => 10000.0,
        'duration_seconds' => 2700.0,
    ]);

    $bung = Activity::factory()->create([
        'name' => 'GPS Glitch 50K',
        'sport_category' => 'running',
        'sport_discipline' => 'road',
        'started_at_local' => CarbonImmutable::parse('2026-10-12 07:00:00'),
        'distance_meters' => 50000.0,
        'duration_seconds' => 1200.0,
    ]);

    // Gear assigned to both
    $shoe = GearItem::factory()->create([
        'name' => 'Daily Trainers',
        'initial_distance_meters' => 0.0,
    ]);
    $good->gearItems()->attach($shoe->id);
    $bung->gearItems()->attach($shoe->id);

    // Best efforts (bung has unrealistically fast 5K)
    $goodEffort = BestEffort::factory()->create([
        'activity_id' => $good->id,
        'sport' => 'running',
        'distance_key' => '5k',
        'distance_meters' => 5000.0,
        'elapsed_seconds' => 1250.0,
        'achieved_at' => $good->started_at_local,
    ]);

    $bungEffort = BestEffort::factory()->create([
        'activity_id' => $bung->id,
        'sport' => 'running',
        'distance_key' => '5k',
        'distance_meters' => 5000.0,
        'elapsed_seconds' => 400.0,
        'achieved_at' => $bung->started_at_local,
    ]);

    // Weather (bung has extreme temperature)
    ActivityWeather::factory()->create([
        'activity_id' => $good->id,
        'temperature_c' => 15.0,
    ]);
    ActivityWeather::factory()->create([
        'activity_id' => $bung->id,
        'temperature_c' => 48.0,
    ]);

    // Matched route cluster containing both
    $cluster = RouteCluster::factory()->create([
        'sport_category' => 'running',
        'representative_activity_id' => $bung->id,
        'activity_count' => 2,
    ]);
    ActivityRouteFingerprint::factory()->create([
        'activity_id' => $good->id,
        'route_cluster_id' => $cluster->id,
        'is_unlinked' => false,
    ]);
    ActivityRouteFingerprint::factory()->create([
        'activity_id' => $bung->id,
        'route_cluster_id' => $cluster->id,
        'is_unlinked' => false,
    ]);

    // Segment where bung holds #1 PR and a climb detection
    $segment = Segment::factory()->create([
        'sport_category' => 'running',
        'kind' => Segment::KIND_CLIMB,
        'effort_count' => 2,
        'detection_count' => 2,
    ]);
    $goodSegEffort = SegmentEffort::factory()->create([
        'segment_id' => $segment->id,
        'activity_id' => $good->id,
        'started_at_local' => $good->started_at_local,
        'elapsed_seconds' => 240.0,
        'rank' => 2,
        'is_pr' => false,
    ]);
    SegmentEffort::factory()->create([
        'segment_id' => $segment->id,
        'activity_id' => $bung->id,
        'started_at_local' => $bung->started_at_local,
        'elapsed_seconds' => 45.0,
        'rank' => 1,
        'is_pr' => true,
    ]);
    $segment->detectedActivities()->attach([$good->id, $bung->id]);

    // Exclude the bung activity
    $exclusions->exclude($bung);

    // 1. Gear mileage excludes bung activity
    $gearList = app(GearService::class)->list();
    expect($gearList['active'])->toHaveCount(1)
        ->and($gearList['active'][0]['activity_count'])->toBe(1)
        ->and($gearList['active'][0]['logged_distance_meters'])->toBe(10000.0)
        ->and($gearList['active'][0]['recent_activities'])->toHaveCount(1)
        ->and($gearList['active'][0]['recent_activities'][0]['id'])->toBe($good->id);

    // 2. SportRegistry counts exclude bung activity
    $counts = app(SportRegistry::class)->settingsPayload()['counts'];
    expect($counts['road_running'])->toBe(1);

    // 3. Best effort row is preserved on DB for restore, but excluded from rankings, endurance hub, pace anchor, and recap
    expect(BestEffort::query()->whereKey($bungEffort->id)->exists())->toBeTrue();

    $goodDetail = app(ActivityQueryService::class)->detail($good);
    expect($goodDetail['bestEfforts'][0]['id'])->toBe($goodEffort->id)
        ->and($goodDetail['bestEfforts'][0]['all_time_rank'])->toBe(1);

    $bungDetail = app(ActivityQueryService::class)->detail($bung);
    expect($bungDetail['bestEfforts'][0]['all_time_rank'])->toBeNull();

    $runningHub = app(EnduranceHubService::class)->hub('running');
    $fiveKBoard = collect($runningHub['modules']['pace']['bestEfforts']['distances'])->firstWhere('key', '5k');
    expect($fiveKBoard['allTimeTop3'])->toHaveCount(1)
        ->and($fiveKBoard['allTimeTop3'][0]['activity_id'])->toBe($good->id)
        ->and($fiveKBoard['allTimeTop3'][0]['elapsed_seconds'])->toBe(1250.0);

    $paceDist = app(PaceDistributionService::class)->forActivity($good, [
        ['time' => 0.0, 'distance' => 0.0, 'speed' => 3.7, 'gap_speed' => 3.7],
        ['time' => 30.0, 'distance' => 111.0, 'speed' => 3.7, 'gap_speed' => 3.7],
        ['time' => 60.0, 'distance' => 222.0, 'speed' => 3.7, 'gap_speed' => 3.7],
    ]);
    expect($paceDist)->not->toBeNull()
        ->and($paceDist['anchor_5k_seconds'])->toBe(1250);

    $recap = app(AnnualRecapService::class)->build(2026);
    expect($recap['milestones']['best_efforts'][0]['activity_id'])->toBe($good->id)
        ->and($recap['weather']['sample_count'])->toBe(1)
        ->and($recap['weather']['hottest_activity']['activity_id'])->toBe($good->id);

    // 4. RouteCluster was refreshed and bung fingerprint detached from it
    $refreshedCluster = $cluster->fresh();
    expect($refreshedCluster)->not->toBeNull()
        ->and($refreshedCluster->activity_count)->toBe(1)
        ->and($refreshedCluster->representative_activity_id)->toBe($good->id)
        ->and(ActivityRouteFingerprint::query()->where('activity_id', $bung->id)->value('route_cluster_id'))->toBeNull();

    // 5. Segment efforts & detections removed and rankings recalculated so legit effort is now #1 PR
    $refreshedSegment = $segment->fresh();
    expect($refreshedSegment->effort_count)->toBe(1)
        ->and($refreshedSegment->detection_count)->toBe(1)
        ->and($refreshedSegment->best_effort_id)->toBe($goodSegEffort->id)
        ->and($goodSegEffort->fresh()?->rank)->toBe(1)
        ->and($goodSegEffort->fresh()?->is_pr)->toBeTrue();
});

it('refuses to sync streams for an excluded activity and never route-matches it', function () {
    $this->mock(ActivityStreamSyncService::class)->shouldNotReceive('sync');

    $activity = Activity::factory()->excluded()->create([
        'sport_category' => 'running',
        'is_indoor' => false,
        'distance_meters' => 5000.0,
    ]);
    $stream = ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'polyline' => exclusionTestLoopPolyline(),
    ]);

    $this->from("/activities/{$activity->id}")
        ->post("/activities/{$activity->id}/sync-streams")
        ->assertRedirect("/activities/{$activity->id}")
        ->assertSessionHas('status', 'Restore this activity before syncing its streams.');

    expect(app(RouteMatchingService::class)->matchActivity($activity, $stream))->toBeNull()
        ->and(ActivityRouteFingerprint::query()->where('activity_id', $activity->id)->exists())->toBeFalse()
        ->and(RouteCluster::query()->count())->toBe(0);
});

it('restores a custom route name and a manual unlink after excluding and restoring activities', function () {
    Queue::fake();

    $matcher = app(RouteMatchingService::class);
    $exclusions = app(ActivityExclusionService::class);

    $named = Activity::factory()->create([
        'sport_category' => 'running',
        'is_indoor' => false,
        'distance_meters' => 5000.0,
    ]);
    $namedStream = ActivityStream::factory()->create([
        'activity_id' => $named->id,
        'polyline' => exclusionTestLoopPolyline(),
    ]);
    $namedCluster = $matcher->matchActivity($named, $namedStream)->cluster;
    $namedCluster->update(['name_override' => 'Harbour Bridge Loop']);

    $unlinked = Activity::factory()->create([
        'sport_category' => 'running',
        'is_indoor' => false,
        'distance_meters' => 5000.0,
    ]);
    $unlinkedStream = ActivityStream::factory()->create([
        'activity_id' => $unlinked->id,
        'polyline' => exclusionTestLoopPolyline(centerLng: 174.9000),
    ]);
    $matcher->unlinkActivity($matcher->matchActivity($unlinked, $unlinkedStream)->cluster, $unlinked);

    $exclusions->exclude($named);
    $exclusions->exclude($unlinked);

    expect($namedCluster->fresh())->toBeNull();

    $exclusions->restore(Activity::withExcluded()->findOrFail($named->id));
    $exclusions->restore(Activity::withExcluded()->findOrFail($unlinked->id));

    $namedFingerprint = ActivityRouteFingerprint::query()->where('activity_id', $named->id)->firstOrFail();
    $unlinkedFingerprint = ActivityRouteFingerprint::query()->where('activity_id', $unlinked->id)->firstOrFail();

    expect($namedFingerprint->cluster?->name_override)->toBe('Harbour Bridge Loop')
        ->and($namedFingerprint->preserved_name_override)->toBeNull()
        ->and($unlinkedFingerprint->is_unlinked)->toBeTrue()
        ->and($unlinkedFingerprint->route_cluster_id)->toBeNull();
});

/**
 * Closed ~5 km loop polyline for route-matching scenarios.
 *
 * @return list<array{0: float, 1: float}>
 */
function exclusionTestLoopPolyline(float $centerLat = -36.8500, float $centerLng = 174.7600): array
{
    $coords = [];

    for ($i = 0; $i < 40; $i++) {
        $angle = ($i / 39) * 2.0 * M_PI;
        $coords[] = [
            round($centerLat + (0.0072 * sin($angle)), 5),
            round($centerLng + (0.0072 * cos($angle)), 5),
        ];
    }

    return $coords;
}
