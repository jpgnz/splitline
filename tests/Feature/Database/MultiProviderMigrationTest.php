<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('backfills pre-migration Garmin rows, preserves FK children, enforces multi-provider uniqueness, and rolls back cleanly', function () {
    $tempPath = tempnam(sys_get_temp_dir(), 'splitline_multi_provider_migration_');
    expect($tempPath)->toBeString();

    $connectionName = 'migration_test';
    $previousDefault = DB::getDefaultConnection();

    config()->set("database.connections.{$connectionName}", [
        'driver' => 'sqlite',
        'database' => $tempPath,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge($connectionName);

    $migrationFiles = glob(database_path('migrations/*.php'));
    expect($migrationFiles)->toBeArray();
    sort($migrationFiles, SORT_STRING);

    $targetMigrationName = '2026_11_25_081212_generalize_telemetry_tables_for_multi_provider.php';
    $preMigrationFiles = [];
    $targetMigrationFile = null;

    foreach ($migrationFiles as $file) {
        if (basename($file) === $targetMigrationName) {
            $targetMigrationFile = $file;

            continue;
        }

        $preMigrationFiles[] = $file;
    }

    expect($targetMigrationFile)->not->toBeNull();

    try {
        DB::setDefaultConnection($connectionName);

        $connection = DB::connection($connectionName);
        expect($connection->transactionLevel())->toBe(0);

        $fkEnabled = $connection->selectOne('PRAGMA foreign_keys');
        expect((int) ($fkEnabled->foreign_keys ?? 0))->toBe(1);

        // 1. Run all pre-migration files on the dedicated non-transactional SQLite database
        foreach ($preMigrationFiles as $migrationFile) {
            $migration = require $migrationFile;
            $migration->up();
        }

        expect(Schema::hasColumn('activities', 'provider'))->toBeFalse()
            ->and(Schema::hasColumn('activities', 'external_id'))->toBeFalse()
            ->and(Schema::hasColumn('weight_measurements', 'provider'))->toBeFalse();

        // 2. Seed pre-migration Garmin rows across all affected tables plus FK child tables
        $now = '2026-11-20 08:00:00';

        $activityId = DB::table('activities')->insertGetId([
            'garmin_activity_id' => 123456789,
            'name' => 'Morning Tempo Run',
            'activity_type_key' => 'running',
            'sport_category' => 'running',
            'sport_discipline' => 'road',
            'is_indoor' => false,
            'started_at_local' => '2026-11-20 07:30:00',
            'started_at_utc' => '2026-11-19 18:30:00',
            'distance_meters' => 10000.0,
            'duration_seconds' => 2500.0,
            'is_favorite' => false,
            'is_pr' => false,
            'is_manual' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $streamId = DB::table('activity_streams')->insertGetId([
            'activity_id' => $activityId,
            'series' => json_encode([['time' => 0, 'distance' => 0]], JSON_THROW_ON_ERROR),
            'polyline' => json_encode([[-36.8485, 174.7633]], JSON_THROW_ON_ERROR),
            'laps' => json_encode([], JSON_THROW_ON_ERROR),
            'km_splits' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $bestEffortId = DB::table('best_efforts')->insertGetId([
            'activity_id' => $activityId,
            'sport' => 'running',
            'distance_key' => '5k',
            'distance_meters' => 5000.0,
            'elapsed_seconds' => 1225.0,
            'achieved_at' => '2026-11-20 07:30:00',
            'source' => 'fit_stream',
            'is_excluded' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $weatherId = DB::table('activity_weathers')->insertGetId([
            'activity_id' => $activityId,
            'provider' => 'open_meteo',
            'model_endpoint' => 'forecast',
            'sampling_mode' => 'route',
            'waypoint_count' => 3,
            'wmo_code' => 1,
            'condition' => 'Mainly Clear',
            'base_condition' => 'Mainly Clear',
            'icon' => 'sun',
            'temperature_c' => 16.5,
            'samples' => json_encode([], JSON_THROW_ON_ERROR),
            'fetched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $weightId = DB::table('weight_measurements')->insertGetId([
            'garmin_sample_pk' => 987654321,
            'calendar_date' => '2026-11-20',
            'measured_at' => '2026-11-20 06:45:00',
            'weight_grams' => 72400,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('daily_metrics')->insert([
            'calendar_date' => '2026-11-20',
            'steps' => 11200,
            'resting_hr' => 48,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('sleep_records')->insert([
            'calendar_date' => '2026-11-20',
            'sleep_seconds' => 28800,
            'sleep_score' => 86,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('hrv_records')->insert([
            'calendar_date' => '2026-11-20',
            'weekly_avg' => 68,
            'last_night_avg' => 72,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('vo2_max_records')->insert([
            'calendar_date' => '2026-11-20',
            'sport' => 'running',
            'vo2_max' => 54.0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('fitness_age_records')->insert([
            'calendar_date' => '2026-11-20',
            'chronological_age' => 33.0,
            'fitness_age' => 27.5,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('weight_goals')->insert([
            'start_date' => '2026-11-01',
            'goal_grams' => 71000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('heart_rate_zones')->insert([
            'sport' => 'running',
            'max_hr' => 190,
            'resting_hr' => 48,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('sync_runs')->insert([
            'type' => 'activities',
            'status' => 'completed',
            'started_at' => $now,
            'finished_at' => $now,
            'items_synced' => 5,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 3. Run the multi-provider migration up()
        $targetMigration = require $targetMigrationFile;
        $targetMigration->up();

        // 4. Verify backfills and FK child preservation
        $migratedActivity = DB::table('activities')->where('id', $activityId)->first();
        expect($migratedActivity)->not->toBeNull()
            ->and($migratedActivity->provider)->toBe('garmin_personal')
            ->and($migratedActivity->external_id)->toBe('123456789')
            ->and((int) $migratedActivity->garmin_activity_id)->toBe(123456789);

        $migratedWeight = DB::table('weight_measurements')->where('id', $weightId)->first();
        expect($migratedWeight)->not->toBeNull()
            ->and($migratedWeight->provider)->toBe('garmin_personal')
            ->and($migratedWeight->external_id)->toBe('987654321')
            ->and((int) $migratedWeight->garmin_sample_pk)->toBe(987654321);

        expect(DB::table('activity_streams')->where('id', $streamId)->where('activity_id', $activityId)->exists())->toBeTrue()
            ->and(DB::table('best_efforts')->where('id', $bestEffortId)->where('activity_id', $activityId)->exists())->toBeTrue()
            ->and(DB::table('activity_weathers')->where('id', $weatherId)->where('activity_id', $activityId)->exists())->toBeTrue();

        foreach ([
            'daily_metrics',
            'sleep_records',
            'hrv_records',
            'vo2_max_records',
            'fitness_age_records',
            'weight_goals',
            'heart_rate_zones',
            'sync_runs',
        ] as $table) {
            expect(DB::table($table)->value('provider'))->toBe('garmin_personal');
        }

        // 5. Non-Garmin fit_upload activity with garmin_activity_id = null succeeds and enforces unique (provider, external_id)
        $fitExternalId = str_repeat('a', 64);

        $fitUploadActivityId = DB::table('activities')->insertGetId([
            'provider' => 'fit_upload',
            'external_id' => $fitExternalId,
            'garmin_activity_id' => null,
            'name' => 'Trail Running 2026-11-20',
            'activity_type_key' => 'trail_running',
            'sport_category' => 'running',
            'sport_discipline' => 'trail',
            'is_indoor' => false,
            'started_at_local' => '2026-11-20 09:00:00',
            'started_at_utc' => '2026-11-19 20:00:00',
            'distance_meters' => 8400.0,
            'duration_seconds' => 2400.0,
            'is_favorite' => false,
            'is_pr' => false,
            'is_manual' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        expect($fitUploadActivityId)->toBeGreaterThan($activityId);

        expect(fn () => DB::table('activities')->insert([
            'provider' => 'fit_upload',
            'external_id' => $fitExternalId,
            'garmin_activity_id' => null,
            'name' => 'Duplicate Trail Run',
            'activity_type_key' => 'trail_running',
            'sport_category' => 'running',
            'is_indoor' => false,
            'started_at_local' => '2026-11-20 09:00:00',
            'started_at_utc' => '2026-11-19 20:00:00',
            'distance_meters' => 8400.0,
            'duration_seconds' => 2400.0,
            'is_favorite' => false,
            'is_pr' => false,
            'is_manual' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]))->toThrow(QueryException::class);

        // 6. Second provider's daily_metrics row for the same calendar_date succeeds while duplicate (provider, calendar_date) fails
        $secondProviderDailyId = DB::table('daily_metrics')->insertGetId([
            'provider' => 'apple_health',
            'calendar_date' => '2026-11-20',
            'steps' => 11450,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        expect($secondProviderDailyId)->toBeGreaterThan(0);

        expect(fn () => DB::table('daily_metrics')->insert([
            'provider' => 'apple_health',
            'calendar_date' => '2026-11-20',
            'steps' => 12000,
            'created_at' => $now,
            'updated_at' => $now,
        ]))->toThrow(QueryException::class);

        // 7. Remove non-Garmin rows and verify down() rolls back cleanly with Garmin rows and FK children intact
        DB::table('activities')->where('id', $fitUploadActivityId)->delete();
        DB::table('daily_metrics')->where('id', $secondProviderDailyId)->delete();

        $targetMigration->down();

        expect(Schema::hasColumn('activities', 'provider'))->toBeFalse()
            ->and(Schema::hasColumn('activities', 'external_id'))->toBeFalse()
            ->and(Schema::hasColumn('weight_measurements', 'provider'))->toBeFalse()
            ->and(Schema::hasColumn('weight_measurements', 'external_id'))->toBeFalse()
            ->and(Schema::hasColumn('daily_metrics', 'provider'))->toBeFalse()
            ->and(Schema::hasColumn('sleep_records', 'provider'))->toBeFalse()
            ->and(Schema::hasColumn('hrv_records', 'provider'))->toBeFalse()
            ->and(Schema::hasColumn('vo2_max_records', 'provider'))->toBeFalse()
            ->and(Schema::hasColumn('fitness_age_records', 'provider'))->toBeFalse()
            ->and(Schema::hasColumn('weight_goals', 'provider'))->toBeFalse()
            ->and(Schema::hasColumn('heart_rate_zones', 'provider'))->toBeFalse()
            ->and(Schema::hasColumn('sync_runs', 'provider'))->toBeFalse();

        expect((int) DB::table('activities')->where('id', $activityId)->value('garmin_activity_id'))->toBe(123456789)
            ->and((int) DB::table('weight_measurements')->where('id', $weightId)->value('garmin_sample_pk'))->toBe(987654321)
            ->and(DB::table('activity_streams')->where('id', $streamId)->where('activity_id', $activityId)->exists())->toBeTrue()
            ->and(DB::table('best_efforts')->where('id', $bestEffortId)->where('activity_id', $activityId)->exists())->toBeTrue()
            ->and(DB::table('activity_weathers')->where('id', $weatherId)->where('activity_id', $activityId)->exists())->toBeTrue();
    } finally {
        DB::setDefaultConnection($previousDefault);
        DB::purge($connectionName);
        config()->offsetUnset("database.connections.{$connectionName}");

        foreach ([$tempPath, "{$tempPath}-wal", "{$tempPath}-shm"] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
});
