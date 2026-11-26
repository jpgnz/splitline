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
        Schema::create('activity_derived_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->unique()->constrained()->cascadeOnDelete();
            $table->float('decoupling_pct')->nullable();
            $table->string('decoupling_metric', 16)->nullable();
            $table->string('decoupling_rating', 24)->nullable();
            $table->float('efficiency_factor')->nullable();
            $table->float('first_half_ef')->nullable();
            $table->float('second_half_ef')->nullable();
            $table->string('pacing_strategy', 24)->nullable();
            $table->float('split_ratio')->nullable();
            $table->float('pace_cv_pct')->nullable();
            $table->float('hr_low_pct')->nullable();
            $table->float('hr_moderate_pct')->nullable();
            $table->float('hr_high_pct')->nullable();
            $table->double('end_lat')->nullable();
            $table->double('end_lng')->nullable();
            $table->double('start_to_end_meters')->nullable();
            $table->boolean('is_loop')->nullable();
            $table->double('bbox_min_lat')->nullable();
            $table->double('bbox_max_lat')->nullable();
            $table->double('bbox_min_lng')->nullable();
            $table->double('bbox_max_lng')->nullable();
            $table->json('route_fingerprint')->nullable();
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->index(['bbox_min_lat', 'bbox_max_lat', 'bbox_min_lng', 'bbox_max_lng'], 'adm_bbox_idx');
            $table->index('computed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_derived_metrics');
    }
};
