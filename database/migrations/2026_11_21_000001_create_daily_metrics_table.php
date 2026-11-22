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
        Schema::create('daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->string('calendar_date', 10)->unique();
            $table->unsignedInteger('steps')->nullable();
            $table->unsignedInteger('step_goal')->nullable();
            $table->double('distance_meters')->nullable();
            $table->unsignedInteger('total_calories')->nullable();
            $table->unsignedInteger('active_calories')->nullable();
            $table->unsignedInteger('bmr_calories')->nullable();
            $table->unsignedSmallInteger('resting_hr')->nullable();
            $table->unsignedSmallInteger('seven_day_avg_rhr')->nullable();
            $table->unsignedSmallInteger('min_hr')->nullable();
            $table->unsignedSmallInteger('max_hr')->nullable();
            $table->smallInteger('avg_stress')->nullable();
            $table->smallInteger('max_stress')->nullable();
            $table->string('stress_qualifier')->nullable();
            $table->unsignedInteger('rest_stress_seconds')->nullable();
            $table->unsignedInteger('low_stress_seconds')->nullable();
            $table->unsignedInteger('medium_stress_seconds')->nullable();
            $table->unsignedInteger('high_stress_seconds')->nullable();
            $table->unsignedSmallInteger('body_battery_current')->nullable();
            $table->unsignedSmallInteger('body_battery_high')->nullable();
            $table->unsignedSmallInteger('body_battery_low')->nullable();
            $table->unsignedSmallInteger('body_battery_charged')->nullable();
            $table->unsignedSmallInteger('body_battery_drained')->nullable();
            $table->smallInteger('body_battery_sleep')->nullable();
            $table->unsignedSmallInteger('moderate_intensity_minutes')->nullable();
            $table->unsignedSmallInteger('vigorous_intensity_minutes')->nullable();
            $table->unsignedSmallInteger('intensity_minutes_goal')->nullable();
            $table->double('floors_ascended')->nullable();
            $table->double('floors_descended')->nullable();
            $table->unsignedSmallInteger('floors_goal')->nullable();
            $table->double('avg_respiration')->nullable();
            $table->double('min_respiration')->nullable();
            $table->double('max_respiration')->nullable();
            $table->double('avg_spo2')->nullable();
            $table->double('lowest_spo2')->nullable();
            $table->double('latest_spo2')->nullable();
            $table->unsignedInteger('hydration_ml')->nullable();
            $table->json('heart_rate_series')->nullable();
            $table->json('body_battery_series')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_metrics');
    }
};
