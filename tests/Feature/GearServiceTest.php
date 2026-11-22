<?php

use App\Models\Activity;
use App\Models\GearItem;
use App\Services\Gear\GearService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'Pacific/Auckland'));
});

it('aggregates distances, alert thresholds, recent activities, and summary metrics across active and retired gear', function () {
    $okShoe = GearItem::factory()->runningShoe()->create([
        'name' => 'Alpha Daily Trainer',
        'initial_distance_meters' => 100000.0,
        'max_distance_meters' => 600000.0,
    ]);

    $warningBike = GearItem::factory()->roadBike()->defaultFor('road_biking')->create([
        'name' => 'Beta Road Bike',
        'initial_distance_meters' => 2500000.0,
        'max_distance_meters' => 3000000.0,
    ]);

    $exceededShoe = GearItem::factory()->runningShoe()->create([
        'name' => 'Gamma Race Shoe',
        'initial_distance_meters' => 580000.0,
        'max_distance_meters' => 600000.0,
    ]);

    $noLimitMtb = GearItem::factory()->mtbBike()->create([
        'name' => 'Delta Trail Bike',
        'initial_distance_meters' => 50000.0,
        'max_distance_meters' => null,
    ]);

    $retiredShoe = GearItem::factory()->runningShoe()->retired('2026-10-15')->create([
        'name' => 'Old Marathon Shoe',
        'initial_distance_meters' => 700000.0,
        'max_distance_meters' => 600000.0,
    ]);

    $service = app(GearService::class);

    // Attach 10 runs to Alpha Daily Trainer to verify the 8-activity cap and DESC ordering
    for ($day = 1; $day <= 10; $day++) {
        $run = Activity::factory()->create([
            'name' => "Run Day {$day}",
            'sport_category' => 'running',
            'started_at_local' => CarbonImmutable::parse(sprintf('2026-11-%02d 07:00:00', $day)),
            'distance_meters' => 10000.0,
            'duration_seconds' => 3000.0,
        ]);

        $service->assignToActivity($run, $okShoe->id);
    }

    // 2500km initial + 100km logged = 2600km / 3000km = 86.7% -> warning
    $roadRide = Activity::factory()->otherBiking()->create([
        'name' => 'Coastal Loop',
        'discipline_override' => 'road',
        'started_at_local' => CarbonImmutable::parse('2026-11-18 07:30:00'),
        'distance_meters' => 100000.0,
        'duration_seconds' => 10800.0,
    ]);
    $service->assignToActivity($roadRide, $warningBike->id);

    // 580km initial + 25km logged = 605km / 600km = 100.8% -> exceeded
    $raceRun = Activity::factory()->create([
        'name' => 'City Half Plus',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-19 06:30:00'),
        'distance_meters' => 25000.0,
        'duration_seconds' => 6600.0,
    ]);
    $service->assignToActivity($raceRun, $exceededShoe->id);

    $result = $service->list();

    expect($result['summary'])->toBe([
        'active_count' => 4,
        'retired_count' => 1,
        'alerts_count' => 2, // warning + exceeded (retired >100% does NOT count as active alert)
        'total_distance_meters' => 4155000.0,
    ]);

    expect($result['active'])->toHaveCount(4)
        ->and($result['retired'])->toHaveCount(1);

    $activeByName = collect($result['active'])->keyBy('name');

    expect($activeByName['Alpha Daily Trainer']['initial_distance_meters'])->toBe(100000.0)
        ->and($activeByName['Alpha Daily Trainer']['logged_distance_meters'])->toBe(100000.0)
        ->and($activeByName['Alpha Daily Trainer']['total_distance_meters'])->toBe(200000.0)
        ->and($activeByName['Alpha Daily Trainer']['usage_pct'])->toBe(33.3)
        ->and($activeByName['Alpha Daily Trainer']['alert_status'])->toBe('ok')
        ->and($activeByName['Alpha Daily Trainer']['activity_count'])->toBe(10)
        ->and($activeByName['Alpha Daily Trainer']['recent_activities'])->toHaveCount(8)
        ->and($activeByName['Alpha Daily Trainer']['recent_activities'][0]['name'])->toBe('Run Day 10')
        ->and($activeByName['Alpha Daily Trainer']['recent_activities'][7]['name'])->toBe('Run Day 3');

    expect($activeByName['Beta Road Bike']['usage_pct'])->toBe(86.7)
        ->and($activeByName['Beta Road Bike']['alert_status'])->toBe('warning')
        ->and($activeByName['Beta Road Bike']['recent_activities'][0]['effective_discipline'])->toBe('road');

    expect($activeByName['Gamma Race Shoe']['usage_pct'])->toBe(100.8)
        ->and($activeByName['Gamma Race Shoe']['alert_status'])->toBe('exceeded');

    expect($activeByName['Delta Trail Bike']['usage_pct'])->toBeNull()
        ->and($activeByName['Delta Trail Bike']['alert_status'])->toBe('ok')
        ->and($activeByName['Delta Trail Bike']['total_distance_meters'])->toBe(50000.0);

    expect($result['retired'][0]['id'])->toBe($retiredShoe->id)
        ->and($result['retired'][0]['is_retired'])->toBeTrue()
        ->and($result['retired'][0]['alert_status'])->toBe('exceeded');
});

it('auto-assigns default gear by sport and effective discipline without overwriting manual assignments', function () {
    $defaultRunShoe = GearItem::factory()->runningShoe()->defaultFor('running')->create(['name' => 'Default Runner']);
    $manualRunShoe = GearItem::factory()->runningShoe()->create(['name' => 'Manual Trail Shoe']);
    $defaultRoadBike = GearItem::factory()->roadBike()->defaultFor('road_biking')->create(['name' => 'Default Road']);
    $defaultMtbBike = GearItem::factory()->mtbBike()->defaultFor('mtb_biking')->create(['name' => 'Default MTB']);

    // Retired gear should never be auto-assigned
    GearItem::factory()->runningShoe()->create([
        'name' => 'Retired Runner',
        'default_sport' => 'running',
        'retired_at' => '2026-09-01',
    ]);

    $service = app(GearService::class);

    $run = Activity::factory()->create(['sport_category' => 'running']);
    $roadOverrideRide = Activity::factory()->otherBiking()->create(['discipline_override' => 'road']);
    $mtbRide = Activity::factory()->mountainBiking()->create();
    $unclassifiedBike = Activity::factory()->otherBiking()->create();
    $walk = Activity::factory()->create(['sport_category' => 'walking', 'activity_type_key' => 'walking']);

    // Pre-assign manual shoe to a second run
    $manualRun = Activity::factory()->create(['sport_category' => 'running']);
    $service->assignToActivity($manualRun, $manualRunShoe->id);

    $service->autoAssignDefaultGear($run);
    $service->autoAssignDefaultGear($roadOverrideRide);
    $service->autoAssignDefaultGear($mtbRide);
    $service->autoAssignDefaultGear($unclassifiedBike);
    $service->autoAssignDefaultGear($walk);
    $service->autoAssignDefaultGear($manualRun);

    expect(DB::table('activity_gear')->where('activity_id', $run->id)->value('gear_item_id'))->toBe($defaultRunShoe->id)
        ->and(DB::table('activity_gear')->where('activity_id', $roadOverrideRide->id)->value('gear_item_id'))->toBe($defaultRoadBike->id)
        ->and(DB::table('activity_gear')->where('activity_id', $mtbRide->id)->value('gear_item_id'))->toBe($defaultMtbBike->id)
        ->and(DB::table('activity_gear')->where('activity_id', $unclassifiedBike->id)->exists())->toBeFalse()
        ->and(DB::table('activity_gear')->where('activity_id', $walk->id)->exists())->toBeFalse()
        ->and(DB::table('activity_gear')->where('activity_id', $manualRun->id)->value('gear_item_id'))->toBe($manualRunShoe->id)
        ->and(DB::table('activity_gear')->where('activity_id', $manualRun->id)->count())->toBe(1);
});

it('builds activity gear picker payloads and supports replacing or clearing assigned gear', function () {
    $activeBike = GearItem::factory()->roadBike()->defaultFor('road_biking')->create(['name' => 'Canyon Aeroad']);
    $activeShoe = GearItem::factory()->runningShoe()->create(['name' => 'ASICS Novablast']);
    $retiredShoe = GearItem::factory()->runningShoe()->retired('2026-08-01')->create(['name' => 'Old Pegasus']);

    $activity = Activity::factory()->roadBiking()->create();
    $service = app(GearService::class);

    $initial = $service->forActivity($activity);

    expect($initial['assigned_id'])->toBeNull()
        ->and($initial['assigned_name'])->toBeNull()
        ->and(array_column($initial['available'], 'name'))->toBe([
            'ASICS Novablast',
            'Canyon Aeroad',
            'Old Pegasus',
        ])
        ->and($initial['available'][2]['is_retired'])->toBeTrue();

    $service->assignToActivity($activity, $activeBike->id);
    $assigned = $service->forActivity($activity);

    expect($assigned['assigned_id'])->toBe($activeBike->id)
        ->and($assigned['assigned_name'])->toBe('Canyon Aeroad');

    // Replace with another item (ensures only one link remains)
    $service->assignToActivity($activity, $activeShoe->id);

    expect(DB::table('activity_gear')->where('activity_id', $activity->id)->count())->toBe(1)
        ->and($service->forActivity($activity)['assigned_id'])->toBe($activeShoe->id);

    // Clear assignment
    $service->assignToActivity($activity, null);

    expect($service->forActivity($activity)['assigned_id'])->toBeNull()
        ->and($service->forActivity($activity)['assigned_name'])->toBeNull()
        ->and($retiredShoe->isRetired())->toBeTrue();
});
