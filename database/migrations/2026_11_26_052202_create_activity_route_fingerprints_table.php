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
        Schema::create('activity_route_fingerprints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('activity_id')
                ->unique()
                ->constrained('activities')
                ->cascadeOnDelete();
            $table->foreignId('route_cluster_id')
                ->nullable()
                ->constrained('route_clusters')
                ->nullOnDelete();
            $table->double('start_lat');
            $table->double('start_lng');
            $table->double('end_lat');
            $table->double('end_lng');
            $table->double('bbox_min_lat');
            $table->double('bbox_max_lat');
            $table->double('bbox_min_lng');
            $table->double('bbox_max_lng');
            $table->double('distance_meters');
            $table->double('start_to_end_meters');
            $table->boolean('is_loop')->default(false);
            $table->json('waypoints');
            $table->text('encoded_preview_polyline')->nullable();
            $table->boolean('is_unlinked')->default(false);
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->index(
                ['bbox_min_lat', 'bbox_max_lat', 'bbox_min_lng', 'bbox_max_lng'],
                'activity_route_fingerprints_bbox_index',
            );
            $table->index(
                ['route_cluster_id', 'is_unlinked'],
                'activity_route_fingerprints_cluster_unlinked_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_route_fingerprints');
    }
};
