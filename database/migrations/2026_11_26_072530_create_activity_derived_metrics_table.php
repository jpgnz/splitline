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
            $table->timestamp('computed_at');
            $table->timestamps();

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
