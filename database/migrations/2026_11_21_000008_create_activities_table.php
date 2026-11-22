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
        Schema::create('activities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('garmin_activity_id')->unique();
            $table->string('name');
            $table->string('activity_type_key', 64)->index();
            $table->string('sport_category', 32)->index();
            $table->string('biking_discipline', 16)->nullable()->index();
            $table->string('discipline_override', 16)->nullable()->index();
            $table->boolean('is_indoor')->default(false);
            $table->dateTime('started_at_local')->index();
            $table->dateTime('started_at_utc')->index();
            $table->string('location_name')->nullable();
            $table->double('start_lat')->nullable();
            $table->double('start_lng')->nullable();
            $table->double('distance_meters')->default(0);
            $table->double('duration_seconds')->default(0);
            $table->double('moving_duration_seconds')->nullable();
            $table->double('elapsed_duration_seconds')->nullable();
            $table->double('elevation_gain')->nullable();
            $table->double('elevation_loss')->nullable();
            $table->double('min_elevation')->nullable();
            $table->double('max_elevation')->nullable();
            $table->double('avg_speed')->nullable();
            $table->double('max_speed')->nullable();
            $table->unsignedInteger('calories')->nullable();
            $table->unsignedSmallInteger('avg_hr')->nullable();
            $table->unsignedSmallInteger('max_hr')->nullable();
            $table->double('avg_cadence')->nullable();
            $table->double('max_cadence')->nullable();
            $table->double('avg_stride_length')->nullable();
            $table->unsignedInteger('steps')->nullable();
            $table->double('aerobic_te')->nullable();
            $table->double('anaerobic_te')->nullable();
            $table->double('training_load')->nullable();
            $table->string('training_effect_label')->nullable();
            $table->smallInteger('body_battery_diff')->nullable();
            $table->double('hr_zone_1_seconds')->nullable();
            $table->double('hr_zone_2_seconds')->nullable();
            $table->double('hr_zone_3_seconds')->nullable();
            $table->double('hr_zone_4_seconds')->nullable();
            $table->double('hr_zone_5_seconds')->nullable();
            $table->double('avg_power')->nullable();
            $table->double('max_power')->nullable();
            $table->double('normalized_power')->nullable();
            $table->double('ftp')->nullable();
            $table->double('tss')->nullable();
            $table->double('intensity_factor')->nullable();
            $table->double('total_work')->nullable();
            $table->double('left_balance')->nullable();
            $table->double('max_20min_power')->nullable();
            $table->double('power_zone_1_seconds')->nullable();
            $table->double('power_zone_2_seconds')->nullable();
            $table->double('power_zone_3_seconds')->nullable();
            $table->double('power_zone_4_seconds')->nullable();
            $table->double('power_zone_5_seconds')->nullable();
            $table->double('power_zone_6_seconds')->nullable();
            $table->double('power_zone_7_seconds')->nullable();
            $table->json('power_curve')->nullable();
            $table->double('grit')->nullable();
            $table->double('avg_flow')->nullable();
            $table->unsignedSmallInteger('jump_count')->nullable();
            $table->double('unpaved_pct')->nullable();
            $table->smallInteger('workout_feel')->nullable();
            $table->smallInteger('workout_rpe')->nullable();
            $table->unsignedSmallInteger('begin_stamina')->nullable();
            $table->unsignedSmallInteger('end_stamina')->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->boolean('is_pr')->default(false);
            $table->boolean('is_manual')->default(false);
            $table->dateTime('streams_synced_at')->nullable()->index();
            $table->json('raw_summary')->nullable();
            $table->timestamps();

            $table->index(['sport_category', 'started_at_local']);
            $table->index(['sport_category', 'biking_discipline', 'started_at_local']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
