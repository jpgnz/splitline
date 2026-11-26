<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->string('provider', 64)->default('garmin_personal')->after('id');
            $table->string('external_id', 191)->nullable()->after('provider');
        });

        DB::table('activities')
            ->select(['id', 'garmin_activity_id'])
            ->whereNull('external_id')
            ->whereNotNull('garmin_activity_id')
            ->orderBy('id')
            ->chunkById(500, function (Collection $rows): void {
                foreach ($rows as $row) {
                    DB::table('activities')
                        ->where('id', $row->id)
                        ->update(['external_id' => (string) $row->garmin_activity_id]);
                }
            });

        Schema::table('activities', function (Blueprint $table): void {
            $table->unsignedBigInteger('garmin_activity_id')->nullable()->change();
            $table->unique(['provider', 'external_id']);
        });

        Schema::table('weight_measurements', function (Blueprint $table): void {
            $table->string('provider', 64)->default('garmin_personal')->after('id');
            $table->string('external_id', 191)->nullable()->after('provider');
        });

        DB::table('weight_measurements')
            ->select(['id', 'garmin_sample_pk'])
            ->whereNull('external_id')
            ->whereNotNull('garmin_sample_pk')
            ->orderBy('id')
            ->chunkById(500, function (Collection $rows): void {
                foreach ($rows as $row) {
                    DB::table('weight_measurements')
                        ->where('id', $row->id)
                        ->update(['external_id' => (string) $row->garmin_sample_pk]);
                }
            });

        Schema::table('weight_measurements', function (Blueprint $table): void {
            $table->unsignedBigInteger('garmin_sample_pk')->nullable()->change();
            $table->unique(['provider', 'external_id']);
        });

        Schema::table('sync_runs', function (Blueprint $table): void {
            $table->string('provider', 64)->default('garmin_personal')->after('id')->index();
        });

        Schema::table('daily_metrics', function (Blueprint $table): void {
            $table->string('provider', 64)->default('garmin_personal')->after('id');
            $table->dropUnique(['calendar_date']);
            $table->unique(['provider', 'calendar_date']);
        });

        Schema::table('sleep_records', function (Blueprint $table): void {
            $table->string('provider', 64)->default('garmin_personal')->after('id');
            $table->dropUnique(['calendar_date']);
            $table->unique(['provider', 'calendar_date']);
        });

        Schema::table('hrv_records', function (Blueprint $table): void {
            $table->string('provider', 64)->default('garmin_personal')->after('id');
            $table->dropUnique(['calendar_date']);
            $table->unique(['provider', 'calendar_date']);
        });

        Schema::table('vo2_max_records', function (Blueprint $table): void {
            $table->string('provider', 64)->default('garmin_personal')->after('id');
            $table->dropUnique(['calendar_date', 'sport']);
            $table->unique(['provider', 'calendar_date', 'sport']);
        });

        Schema::table('fitness_age_records', function (Blueprint $table): void {
            $table->string('provider', 64)->default('garmin_personal')->after('id');
            $table->dropUnique(['calendar_date']);
            $table->unique(['provider', 'calendar_date']);
        });

        Schema::table('weight_goals', function (Blueprint $table): void {
            $table->string('provider', 64)->default('garmin_personal')->after('id');
            $table->dropUnique(['start_date']);
            $table->unique(['provider', 'start_date']);
        });

        Schema::table('heart_rate_zones', function (Blueprint $table): void {
            $table->string('provider', 64)->default('garmin_personal')->after('id');
            $table->dropUnique(['sport']);
            $table->unique(['provider', 'sport']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('heart_rate_zones', function (Blueprint $table): void {
            $table->dropUnique(['provider', 'sport']);
            $table->unique(['sport']);
            $table->dropColumn('provider');
        });

        Schema::table('weight_goals', function (Blueprint $table): void {
            $table->dropUnique(['provider', 'start_date']);
            $table->unique(['start_date']);
            $table->dropColumn('provider');
        });

        Schema::table('fitness_age_records', function (Blueprint $table): void {
            $table->dropUnique(['provider', 'calendar_date']);
            $table->unique(['calendar_date']);
            $table->dropColumn('provider');
        });

        Schema::table('vo2_max_records', function (Blueprint $table): void {
            $table->dropUnique(['provider', 'calendar_date', 'sport']);
            $table->unique(['calendar_date', 'sport']);
            $table->dropColumn('provider');
        });

        Schema::table('hrv_records', function (Blueprint $table): void {
            $table->dropUnique(['provider', 'calendar_date']);
            $table->unique(['calendar_date']);
            $table->dropColumn('provider');
        });

        Schema::table('sleep_records', function (Blueprint $table): void {
            $table->dropUnique(['provider', 'calendar_date']);
            $table->unique(['calendar_date']);
            $table->dropColumn('provider');
        });

        Schema::table('daily_metrics', function (Blueprint $table): void {
            $table->dropUnique(['provider', 'calendar_date']);
            $table->unique(['calendar_date']);
            $table->dropColumn('provider');
        });

        Schema::table('sync_runs', function (Blueprint $table): void {
            $table->dropIndex(['provider']);
            $table->dropColumn('provider');
        });

        Schema::table('weight_measurements', function (Blueprint $table): void {
            $table->dropUnique(['provider', 'external_id']);
            $table->dropColumn(['provider', 'external_id']);
        });

        Schema::table('weight_measurements', function (Blueprint $table): void {
            $table->unsignedBigInteger('garmin_sample_pk')->nullable(false)->change();
        });

        Schema::table('activities', function (Blueprint $table): void {
            $table->dropUnique(['provider', 'external_id']);
            $table->dropColumn(['provider', 'external_id']);
        });

        Schema::table('activities', function (Blueprint $table): void {
            $table->unsignedBigInteger('garmin_activity_id')->nullable(false)->change();
        });
    }
};
