<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->float('avg_grade_adjusted_speed')->nullable()->after('max_speed');
            $table->unsignedInteger('bmr_calories')->nullable()->after('calories');
            $table->unsignedInteger('sweat_loss_ml')->nullable()->after('bmr_calories');
            $table->float('avg_vertical_oscillation')->nullable()->after('avg_stride_length');
            $table->float('avg_vertical_ratio')->nullable()->after('avg_vertical_oscillation');
            $table->float('avg_ground_contact_time')->nullable()->after('avg_vertical_ratio');
            $table->string('aerobic_te_message')->nullable()->after('training_effect_label');
            $table->string('anaerobic_te_message')->nullable()->after('aerobic_te_message');
            $table->unsignedTinyInteger('begin_body_battery')->nullable()->after('body_battery_diff');
            $table->unsignedTinyInteger('end_body_battery')->nullable()->after('begin_body_battery');
            $table->float('avg_left_torque_effectiveness')->nullable()->after('left_balance');
            $table->float('avg_right_torque_effectiveness')->nullable()->after('avg_left_torque_effectiveness');
            $table->float('avg_left_pedal_smoothness')->nullable()->after('avg_right_torque_effectiveness');
            $table->float('avg_right_pedal_smoothness')->nullable()->after('avg_left_pedal_smoothness');
            $table->float('threshold_power')->nullable()->after('ftp');
            $table->json('power_zone_bounds')->nullable()->after('power_zone_7_seconds');
            $table->boolean('wind_enabled')->nullable()->after('power_curve');
            $table->unsignedTinyInteger('min_stamina')->nullable()->after('end_stamina');
            $table->unsignedTinyInteger('execution_score')->nullable()->after('workout_rpe');
            $table->json('workout_meta')->nullable()->after('execution_score');
            $table->float('avg_respiration_rate')->nullable()->after('min_stamina');
            $table->float('min_respiration_rate')->nullable()->after('avg_respiration_rate');
            $table->float('max_respiration_rate')->nullable()->after('min_respiration_rate');
            $table->float('avg_temperature')->nullable()->after('max_respiration_rate');
            $table->float('min_temperature')->nullable()->after('avg_temperature');
            $table->float('max_temperature')->nullable()->after('min_temperature');
            $table->unsignedSmallInteger('moderate_intensity_minutes')->nullable()->after('max_temperature');
            $table->unsignedSmallInteger('vigorous_intensity_minutes')->nullable()->after('moderate_intensity_minutes');
            $table->float('run_duration_seconds')->nullable()->after('vigorous_intensity_minutes');
            $table->float('walk_duration_seconds')->nullable()->after('run_duration_seconds');
            $table->float('idle_duration_seconds')->nullable()->after('walk_duration_seconds');
        });

        Schema::table('activity_streams', function (Blueprint $table): void {
            $table->json('run_walk_segments')->nullable()->after('km_splits');
            $table->json('jumps')->nullable()->after('run_walk_segments');
        });

        Schema::table('best_efforts', function (Blueprint $table): void {
            $table->unsignedSmallInteger('avg_hr')->nullable()->after('start_offset_seconds');
            $table->float('elevation_delta')->nullable()->after('avg_hr');
            $table->float('start_distance_meters')->nullable()->after('elevation_delta');
            $table->float('end_distance_meters')->nullable()->after('start_distance_meters');
        });
    }

    public function down(): void
    {
        Schema::table('best_efforts', function (Blueprint $table): void {
            $table->dropColumn([
                'avg_hr',
                'elevation_delta',
                'start_distance_meters',
                'end_distance_meters',
            ]);
        });

        Schema::table('activity_streams', function (Blueprint $table): void {
            $table->dropColumn([
                'run_walk_segments',
                'jumps',
            ]);
        });

        Schema::table('activities', function (Blueprint $table): void {
            $table->dropColumn([
                'avg_grade_adjusted_speed',
                'bmr_calories',
                'sweat_loss_ml',
                'avg_vertical_oscillation',
                'avg_vertical_ratio',
                'avg_ground_contact_time',
                'aerobic_te_message',
                'anaerobic_te_message',
                'begin_body_battery',
                'end_body_battery',
                'avg_left_torque_effectiveness',
                'avg_right_torque_effectiveness',
                'avg_left_pedal_smoothness',
                'avg_right_pedal_smoothness',
                'threshold_power',
                'power_zone_bounds',
                'wind_enabled',
                'min_stamina',
                'execution_score',
                'workout_meta',
                'avg_respiration_rate',
                'min_respiration_rate',
                'max_respiration_rate',
                'avg_temperature',
                'min_temperature',
                'max_temperature',
                'moderate_intensity_minutes',
                'vigorous_intensity_minutes',
                'run_duration_seconds',
                'walk_duration_seconds',
                'idle_duration_seconds',
            ]);
        });
    }
};
