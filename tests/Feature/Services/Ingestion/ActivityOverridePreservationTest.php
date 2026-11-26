<?php

use App\Models\BestEffort;
use App\Services\Activities\ActivityQueryService;
use App\Services\Ingestion\TelemetryPersistenceService;
use Carbon\CarbonImmutable;
use Splitline\Core\Data\NormalizedActivity;

function makeGarminActivityDto(array $overrides = []): NormalizedActivity
{
    $startedLocal = CarbonImmutable::parse('2026-11-20 07:00:00', 'Pacific/Auckland');
    $startedUtc = $startedLocal->setTimezone('UTC');

    return new NormalizedActivity(
        provider: 'garmin_personal',
        externalId: (string) ($overrides['externalId'] ?? '555001'),
        name: (string) ($overrides['name'] ?? 'Auckland Running'),
        activityTypeKey: (string) ($overrides['activityTypeKey'] ?? 'running'),
        sportCategory: (string) ($overrides['sportCategory'] ?? 'running'),
        sportDiscipline: array_key_exists('sportDiscipline', $overrides) ? $overrides['sportDiscipline'] : 'road',
        isIndoor: (bool) ($overrides['isIndoor'] ?? false),
        startedAtLocal: $startedLocal,
        startedAtUtc: $startedUtc,
        distanceMeters: (float) ($overrides['distanceMeters'] ?? 10000.0),
        durationSeconds: (float) ($overrides['durationSeconds'] ?? 2700.0),
        isFavorite: (bool) ($overrides['isFavorite'] ?? false),
        seededSplits: $overrides['seededSplits'] ?? [],
    );
}

it('preserves name, sport category, and favorite overrides across Garmin re-syncs while updating garmin originals', function () {
    $persistence = app(TelemetryPersistenceService::class);

    // Initial Garmin sync
    $activity = $persistence->upsertActivity(makeGarminActivityDto([
        'externalId' => '88001',
        'name' => 'Morning Run',
        'sportCategory' => 'running',
        'isFavorite' => false,
    ]), dispatchEnrichments: false);

    expect($activity->name)->toBe('Morning Run')
        ->and($activity->garmin_name)->toBe('Morning Run')
        ->and($activity->sport_category)->toBe('running')
        ->and($activity->garmin_sport_category)->toBe('running')
        ->and($activity->is_favorite)->toBeFalse()
        ->and($activity->garmin_is_favorite)->toBeFalse();

    // Local user/MCP overrides applied
    $activity->update([
        'name' => 'Mission Bay Marathon Tune-Up',
        'name_override' => 'Mission Bay Marathon Tune-Up',
        'sport_category' => 'hiking',
        'sport_category_override' => 'hiking',
        'is_favorite' => true,
        'favorite_override' => true,
    ]);

    // Subsequent Garmin sync returns different raw values + running seeded splits
    $resynced = $persistence->upsertActivity(makeGarminActivityDto([
        'externalId' => '88001',
        'name' => 'Garmin Renamed Run',
        'sportCategory' => 'running',
        'isFavorite' => false,
        'seededSplits' => [
            ['distance_key' => '5k', 'distance_meters' => 5000.0, 'elapsed_seconds' => 1300.0, 'source' => 'garmin_split'],
        ],
    ]), dispatchEnrichments: false);

    expect($resynced->id)->toBe($activity->id)
        ->and($resynced->garmin_name)->toBe('Garmin Renamed Run')
        ->and($resynced->name)->toBe('Mission Bay Marathon Tune-Up')
        ->and($resynced->name_override)->toBe('Mission Bay Marathon Tune-Up')
        ->and($resynced->garmin_sport_category)->toBe('running')
        ->and($resynced->sport_category)->toBe('hiking')
        ->and($resynced->sport_category_override)->toBe('hiking')
        ->and($resynced->garmin_is_favorite)->toBeFalse()
        ->and($resynced->is_favorite)->toBeTrue()
        ->and($resynced->favorite_override)->toBeTrue()
        // seedBestEffortsFromSplits respects canonical sport_category ('hiking' -> no running PR seeded)
        ->and(BestEffort::query()->where('activity_id', $resynced->id)->count())->toBe(0);
});

it('falls back to Garmin values when name or category overrides are blank and respects explicit false favorite override', function () {
    $persistence = app(TelemetryPersistenceService::class);

    $activity = $persistence->upsertActivity(makeGarminActivityDto([
        'externalId' => '88002',
        'name' => 'Initial Run',
        'sportCategory' => 'running',
        'isFavorite' => true,
    ]), dispatchEnrichments: false);

    $activity->update([
        'name_override' => '   ',
        'sport_category_override' => '',
        'favorite_override' => false,
        'is_favorite' => false,
    ]);

    $resynced = $persistence->upsertActivity(makeGarminActivityDto([
        'externalId' => '88002',
        'name' => 'Updated Garmin Run',
        'sportCategory' => 'running',
        'isFavorite' => true,
    ]), dispatchEnrichments: false);

    expect($resynced->name)->toBe('Updated Garmin Run')
        ->and($resynced->garmin_name)->toBe('Updated Garmin Run')
        ->and($resynced->sport_category)->toBe('running')
        ->and($resynced->is_favorite)->toBeFalse()
        ->and($resynced->garmin_is_favorite)->toBeTrue()
        ->and($resynced->favorite_override)->toBeFalse();
});

it('sets favorite_override on toggleFavorite so re-syncs preserve the toggle and exposes override metadata in detail', function () {
    $persistence = app(TelemetryPersistenceService::class);

    $activity = $persistence->upsertActivity(makeGarminActivityDto([
        'externalId' => '88003',
        'name' => 'Harbour Tempo',
        'sportCategory' => 'running',
        'isFavorite' => false,
    ]), dispatchEnrichments: false);

    $this->from("/activities/{$activity->id}")
        ->post("/activities/{$activity->id}/favorite")
        ->assertRedirect("/activities/{$activity->id}");

    $activity->refresh();

    expect($activity->is_favorite)->toBeTrue()
        ->and($activity->favorite_override)->toBeTrue()
        ->and($activity->garmin_is_favorite)->toBeFalse();

    // Garmin re-sync with isFavorite=false must not clobber the user's favorite toggle
    $persistence->upsertActivity(makeGarminActivityDto([
        'externalId' => '88003',
        'name' => 'Harbour Tempo',
        'sportCategory' => 'running',
        'isFavorite' => false,
    ]), dispatchEnrichments: false);

    $activity->refresh();

    expect($activity->is_favorite)->toBeTrue()
        ->and($activity->favorite_override)->toBeTrue();

    // Toggling off sets favorite_override = false
    $this->from("/activities/{$activity->id}")
        ->post("/activities/{$activity->id}/favorite")
        ->assertRedirect("/activities/{$activity->id}");

    $activity->refresh();
    expect($activity->is_favorite)->toBeFalse()
        ->and($activity->favorite_override)->toBeFalse();

    $activity->update([
        'name' => 'Custom Harbour Tempo',
        'name_override' => 'Custom Harbour Tempo',
        'sport_category' => 'walking',
        'sport_category_override' => 'walking',
    ]);

    $detail = app(ActivityQueryService::class)->detail($activity);

    expect($detail['activity']['garmin_name'])->toBe('Harbour Tempo')
        ->and($detail['activity']['name_override'])->toBe('Custom Harbour Tempo')
        ->and($detail['activity']['garmin_sport_category'])->toBe('running')
        ->and($detail['activity']['sport_category_override'])->toBe('walking')
        ->and($detail['activity']['favorite_override'])->toBeFalse();
});
