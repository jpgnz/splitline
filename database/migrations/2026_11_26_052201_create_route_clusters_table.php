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
        Schema::create('route_clusters', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('name_override', 160)->nullable();
            $table->string('sport_category', 32);
            $table->foreignId('representative_activity_id')
                ->nullable()
                ->constrained('activities')
                ->nullOnDelete();
            $table->boolean('is_loop')->default(false);
            $table->double('distance_meters');
            $table->double('elevation_gain')->nullable();
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
            $table->unsignedInteger('activity_count')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['sport_category', 'activity_count']);
            $table->index(
                ['bbox_min_lat', 'bbox_max_lat', 'bbox_min_lng', 'bbox_max_lng'],
                'route_clusters_bbox_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('route_clusters');
    }
};
