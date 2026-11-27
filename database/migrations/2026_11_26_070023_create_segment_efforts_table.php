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
        Schema::create('segment_efforts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('segment_id')
                ->constrained('segments')
                ->cascadeOnDelete();
            $table->foreignId('activity_id')
                ->constrained('activities')
                ->cascadeOnDelete();
            $table->timestamp('started_at_local');
            $table->unsignedInteger('start_index');
            $table->unsignedInteger('end_index');
            $table->double('start_distance_meters');
            $table->double('end_distance_meters');
            $table->double('distance_meters');
            $table->double('elapsed_seconds');
            $table->double('moving_seconds')->nullable();
            $table->double('avg_speed')->nullable();
            $table->double('elevation_gain')->nullable();
            $table->double('avg_grade_pct')->nullable();
            $table->double('vam')->nullable();
            $table->unsignedSmallInteger('avg_hr')->nullable();
            $table->unsignedSmallInteger('max_hr')->nullable();
            $table->double('avg_power')->nullable();
            $table->double('w_per_kg')->nullable();
            $table->double('avg_cadence')->nullable();
            $table->unsignedInteger('rank')->nullable();
            $table->boolean('is_pr')->default(false);
            $table->timestamps();

            $table->unique(
                ['segment_id', 'activity_id', 'start_index'],
                'segment_efforts_unique_pass',
            );
            $table->index(['segment_id', 'elapsed_seconds']);
            $table->index(['segment_id', 'started_at_local']);
            $table->index(['activity_id', 'start_distance_meters']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('segment_efforts');
    }
};
