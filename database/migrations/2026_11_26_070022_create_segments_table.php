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
        Schema::create('segments', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('name_override', 160)->nullable();
            $table->string('place_label', 160)->nullable();
            $table->string('kind', 16)->default('climb');
            $table->string('sport_category', 32);
            $table->foreignId('source_activity_id')
                ->nullable()
                ->constrained('activities')
                ->nullOnDelete();
            $table->string('climb_category', 20)->nullable();
            $table->double('distance_meters');
            $table->double('elevation_gain')->nullable();
            $table->double('elevation_loss')->nullable();
            $table->double('avg_grade_pct')->nullable();
            $table->double('max_grade_pct')->nullable();
            $table->double('fiets_score')->nullable();
            $table->double('start_lat');
            $table->double('start_lng');
            $table->double('end_lat');
            $table->double('end_lng');
            $table->double('bbox_min_lat');
            $table->double('bbox_max_lat');
            $table->double('bbox_min_lng');
            $table->double('bbox_max_lng');
            $table->json('waypoints');
            $table->text('encoded_polyline')->nullable();
            $table->boolean('is_starred')->default(false);
            $table->unsignedInteger('effort_count')->default(0);
            $table->double('best_elapsed_seconds')->nullable();
            $table->unsignedBigInteger('best_effort_id')->nullable()->index();
            $table->timestamp('last_effort_at')->nullable();
            $table->timestamp('geocoded_at')->nullable()->index();
            $table->timestamps();

            $table->index(['sport_category', 'kind']);
            $table->index(['kind', 'climb_category']);
            $table->index('is_starred');
            $table->index(
                ['bbox_min_lat', 'bbox_max_lat', 'bbox_min_lng', 'bbox_max_lng'],
                'segments_bbox_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('segments');
    }
};
