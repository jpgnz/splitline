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
        Schema::create('best_efforts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->string('sport', 32);
            $table->string('distance_key', 32);
            $table->double('distance_meters');
            $table->double('elapsed_seconds');
            $table->double('moving_seconds')->nullable();
            $table->double('start_offset_seconds')->nullable();
            $table->dateTime('achieved_at')->index();
            $table->string('source', 32);
            $table->double('override_seconds')->nullable();
            $table->boolean('is_excluded')->default(false);
            $table->timestamps();

            $table->unique(['activity_id', 'sport', 'distance_key']);
            $table->index(['sport', 'distance_key', 'is_excluded', 'elapsed_seconds'], 'best_efforts_leaderboard_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('best_efforts');
    }
};
