<?php

use App\Http\Controllers\GearController;
use App\Models\Activity;
use App\Models\GearItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'Pacific/Auckland'));

    $hasGearRoute = collect(Route::getRoutes()->getRoutes())
        ->contains(fn ($route): bool => $route->uri() === 'gear');

    if (! $hasGearRoute) {
        Route::middleware('web')->group(function (): void {
            Route::get('/gear', [GearController::class, 'index'])->name('gear.index');
            Route::post('/gear', [GearController::class, 'store'])->name('gear.store');
            Route::patch('/gear/{gearItem}', [GearController::class, 'update'])->name('gear.update');
            Route::post('/gear/{gearItem}/retire', [GearController::class, 'toggleRetire'])->name('gear.retire');
            Route::delete('/gear/{gearItem}', [GearController::class, 'destroy'])->name('gear.destroy');
            Route::patch('/activities/{activity}/gear', [GearController::class, 'assignToActivity'])->name('activities.gear.update');
        });

        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
    }
});

it('renders the gear tracking page with active, retired, and summary props', function () {
    GearItem::factory()->runningShoe()->defaultFor('running')->create([
        'name' => 'Novablast 4',
        'initial_distance_meters' => 150000.0,
        'max_distance_meters' => 600000.0,
    ]);

    GearItem::factory()->roadBike()->retired('2026-09-15')->create([
        'name' => 'Old Allez',
        'initial_distance_meters' => 1000000.0,
    ]);

    $this->get('/gear')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Gear/Index')
            ->has('active', 1)
            ->where('active.0.name', 'Novablast 4')
            ->has('retired', 1)
            ->where('retired.0.name', 'Old Allez')
            ->where('summary.active_count', 1)
            ->where('summary.retired_count', 1)
            ->where('summary.alerts_count', 0)
            ->where('summary.total_distance_meters', 1150000),
        );
});

it('creates a gear item and enforces a single active default per sport', function () {
    $previousRunDefault = GearItem::factory()->runningShoe()->defaultFor('running')->create([
        'name' => 'Previous Runner',
    ]);
    $roadDefault = GearItem::factory()->roadBike()->defaultFor('road_biking')->create([
        'name' => 'Keep Road Default',
    ]);

    $this->from('/gear')
        ->post('/gear', [
            'name' => 'ASICS Superblast 2',
            'brand' => 'ASICS',
            'model' => 'Superblast 2',
            'type' => 'running_shoe',
            'default_sport' => 'running',
            'initial_distance_km' => 42.5,
            'max_distance_km' => 650,
            'purchased_at' => '2026-11-01',
            'notes' => 'Long run rotation',
        ])
        ->assertRedirect('/gear')
        ->assertSessionHas('status', 'Gear item added.');

    $created = GearItem::query()->where('name', 'ASICS Superblast 2')->first();

    expect($created)->not->toBeNull()
        ->and($created?->brand)->toBe('ASICS')
        ->and($created?->model)->toBe('Superblast 2')
        ->and($created?->type)->toBe('running_shoe')
        ->and($created?->default_sport)->toBe('running')
        ->and($created?->initial_distance_meters)->toBe(42500.0)
        ->and($created?->max_distance_meters)->toBe(650000.0)
        ->and($created?->purchased_at)->toBe('2026-11-01')
        ->and($created?->notes)->toBe('Long run rotation')
        ->and($previousRunDefault->fresh()?->default_sport)->toBeNull()
        ->and($roadDefault->fresh()?->default_sport)->toBe('road_biking');
});

it('updates a gear item and clears competing default sport on other active gear', function () {
    $existingDefault = GearItem::factory()->mtbBike()->defaultFor('mtb_biking')->create([
        'name' => 'Old Trail Bike',
    ]);
    $target = GearItem::factory()->mtbBike()->create([
        'name' => 'New Trail Bike',
        'default_sport' => null,
    ]);

    $this->from('/gear')
        ->patch("/gear/{$target->id}", [
            'name' => 'Santa Cruz Tallboy CC',
            'brand' => 'Santa Cruz',
            'model' => 'Tallboy CC',
            'type' => 'mtb_bike',
            'default_sport' => 'mtb_biking',
            'initial_distance_km' => 120,
            'max_distance_km' => 2500,
            'purchased_at' => '2026-10-10',
            'notes' => 'Fresh sealant',
        ])
        ->assertRedirect('/gear')
        ->assertSessionHas('status', 'Gear item updated.');

    $freshTarget = $target->fresh();

    expect($freshTarget?->name)->toBe('Santa Cruz Tallboy CC')
        ->and($freshTarget?->default_sport)->toBe('mtb_biking')
        ->and($freshTarget?->initial_distance_meters)->toBe(120000.0)
        ->and($freshTarget?->max_distance_meters)->toBe(2500000.0)
        ->and($existingDefault->fresh()?->default_sport)->toBeNull();
});

it('toggles gear retirement and clears default sport when retiring', function () {
    $shoe = GearItem::factory()->runningShoe()->defaultFor('running')->create();

    $this->from('/gear')
        ->post("/gear/{$shoe->id}/retire")
        ->assertRedirect('/gear')
        ->assertSessionHas('status', 'Gear item retired.');

    expect($shoe->fresh()?->retired_at)->toBe('2026-11-21')
        ->and($shoe->fresh()?->default_sport)->toBeNull();

    $this->from('/gear')
        ->post("/gear/{$shoe->id}/retire")
        ->assertRedirect('/gear')
        ->assertSessionHas('status', 'Gear item reactivated.');

    expect($shoe->fresh()?->retired_at)->toBeNull();
});

it('deletes a gear item and cascades activity_gear pivot rows', function () {
    $shoe = GearItem::factory()->runningShoe()->create();
    $activity = Activity::factory()->create();

    DB::table('activity_gear')->insert([
        'activity_id' => $activity->id,
        'gear_item_id' => $shoe->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->from('/gear')
        ->delete("/gear/{$shoe->id}")
        ->assertRedirect('/gear')
        ->assertSessionHas('status', 'Gear item deleted.');

    expect(GearItem::query()->whereKey($shoe->id)->exists())->toBeFalse()
        ->and(DB::table('activity_gear')->where('gear_item_id', $shoe->id)->exists())->toBeFalse();
});

it('assigns and clears gear on an activity', function () {
    $activity = Activity::factory()->roadBiking()->create();
    $bike = GearItem::factory()->roadBike()->create();

    $this->from("/activities/{$activity->id}")
        ->patch("/activities/{$activity->id}/gear", [
            'gear_item_id' => $bike->id,
        ])
        ->assertRedirect("/activities/{$activity->id}")
        ->assertSessionHas('status', 'Activity gear updated.');

    expect(DB::table('activity_gear')->where('activity_id', $activity->id)->value('gear_item_id'))
        ->toBe($bike->id);

    $this->from("/activities/{$activity->id}")
        ->patch("/activities/{$activity->id}/gear", [
            'gear_item_id' => null,
        ])
        ->assertRedirect("/activities/{$activity->id}")
        ->assertSessionHas('status', 'Activity gear cleared.');

    expect(DB::table('activity_gear')->where('activity_id', $activity->id)->exists())
        ->toBeFalse();
});

it('validates gear creation, update, and activity assignment payloads', function () {
    $gear = GearItem::factory()->runningShoe()->create();
    $activity = Activity::factory()->create();

    $this->from('/gear')
        ->post('/gear', [
            'name' => '',
            'type' => 'invalid_type',
            'default_sport' => 'skydiving',
            'initial_distance_km' => -5,
            'max_distance_km' => 0,
            'purchased_at' => '21-11-2026',
        ])
        ->assertRedirect('/gear')
        ->assertSessionHasErrors([
            'name',
            'type',
            'default_sport',
            'initial_distance_km',
            'max_distance_km',
            'purchased_at',
        ]);

    $this->from('/gear')
        ->patch("/gear/{$gear->id}", [
            'name' => '',
            'type' => 'bogus',
        ])
        ->assertRedirect('/gear')
        ->assertSessionHasErrors(['name', 'type']);

    $this->from("/activities/{$activity->id}")
        ->patch("/activities/{$activity->id}/gear", [
            'gear_item_id' => 999999,
        ])
        ->assertRedirect("/activities/{$activity->id}")
        ->assertSessionHasErrors(['gear_item_id']);
});
