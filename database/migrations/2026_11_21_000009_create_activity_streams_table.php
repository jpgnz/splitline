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
        Schema::create('activity_streams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('activity_id')->unique()->constrained('activities')->cascadeOnDelete();
            $table->json('series')->nullable();
            $table->json('polyline')->nullable();
            $table->json('laps')->nullable();
            $table->json('km_splits')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_streams');
    }
};
