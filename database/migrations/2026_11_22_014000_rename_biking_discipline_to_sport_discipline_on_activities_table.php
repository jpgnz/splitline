<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            if (Schema::hasColumn('activities', 'biking_discipline') && ! Schema::hasColumn('activities', 'sport_discipline')) {
                $table->renameColumn('biking_discipline', 'sport_discipline');
            }
        });

        Schema::table('activities', function (Blueprint $table): void {
            $table->string('sport_discipline', 32)->nullable()->change();
            $table->string('discipline_override', 32)->nullable()->change();
        });

        // Backfill running disciplines
        DB::table('activities')
            ->where('sport_category', 'running')
            ->whereIn('activity_type_key', ['trail_running', 'ultra_run', 'obstacle_run'])
            ->update(['sport_discipline' => 'trail']);

        DB::table('activities')
            ->where('sport_category', 'running')
            ->where('activity_type_key', 'track_running')
            ->update(['sport_discipline' => 'track']);

        DB::table('activities')
            ->where('sport_category', 'running')
            ->whereNull('sport_discipline')
            ->update(['sport_discipline' => 'road']);

        // Backfill cycling disciplines (gravel, ebike, commute)
        DB::table('activities')
            ->where('sport_category', 'biking')
            ->whereIn('activity_type_key', ['gravel_cycling', 'cyclocross'])
            ->update(['sport_discipline' => 'gravel']);

        DB::table('activities')
            ->where('sport_category', 'biking')
            ->whereIn('activity_type_key', ['e_bike_mountain', 'e_bike_fitness', 'e_gravel_cycling'])
            ->update(['sport_discipline' => 'ebike']);

        DB::table('activities')
            ->where('sport_category', 'biking')
            ->where('activity_type_key', 'commuting')
            ->update(['sport_discipline' => 'commute']);

        // Backfill swimming sport_category
        DB::table('activities')
            ->whereIn('activity_type_key', ['lap_swimming', 'open_water_swimming', 'swimming'])
            ->update([
                'sport_category' => 'swimming',
                'sport_discipline' => null,
            ]);
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            if (Schema::hasColumn('activities', 'sport_discipline') && ! Schema::hasColumn('activities', 'biking_discipline')) {
                $table->renameColumn('sport_discipline', 'biking_discipline');
            }
        });
    }
};
