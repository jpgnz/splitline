<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('activity_weathers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('activity_id')->unique()->constrained('activities')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('model_endpoint', 32);
            $table->string('sampling_mode', 16)->index();
            $table->unsignedSmallInteger('waypoint_count');
            $table->unsignedSmallInteger('wmo_code')->nullable();
            $table->string('condition', 40);
            $table->string('base_condition', 40)->nullable();
            $table->string('icon', 32);
            $table->float('temperature_c')->nullable();
            $table->float('min_temperature_c')->nullable();
            $table->float('max_temperature_c')->nullable();
            $table->float('apparent_temperature_c')->nullable();
            $table->unsignedTinyInteger('humidity_pct')->nullable();
            $table->float('dew_point_c')->nullable();
            $table->float('wind_speed_kmh')->nullable();
            $table->float('wind_gusts_kmh')->nullable();
            $table->unsignedSmallInteger('wind_direction_deg')->nullable();
            $table->string('wind_direction_compass', 4)->nullable();
            $table->float('precipitation_mm')->nullable();
            $table->unsignedTinyInteger('cloud_cover_pct')->nullable();
            $table->float('surface_pressure_hpa')->nullable();
            $table->float('headwind_pct')->nullable();
            $table->float('crosswind_pct')->nullable();
            $table->float('tailwind_pct')->nullable();
            $table->float('avg_effective_wind_kmh')->nullable();
            $table->json('samples');
            $table->timestamp('fetched_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_weathers');
    }
};
