<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('garmin_name')->nullable()->after('name');
            $table->string('name_override')->nullable()->after('garmin_name');
            $table->string('garmin_sport_category', 32)->nullable()->after('sport_category');
            $table->string('sport_category_override', 32)->nullable()->after('garmin_sport_category');
            $table->boolean('garmin_is_favorite')->default(false)->after('is_favorite');
            $table->boolean('favorite_override')->nullable()->after('garmin_is_favorite');
        });

        DB::table('activities')->update([
            'garmin_name' => DB::raw('name'),
            'garmin_sport_category' => DB::raw('sport_category'),
            'garmin_is_favorite' => DB::raw('is_favorite'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn([
                'garmin_name',
                'name_override',
                'garmin_sport_category',
                'sport_category_override',
                'garmin_is_favorite',
                'favorite_override',
            ]);
        });
    }
};
