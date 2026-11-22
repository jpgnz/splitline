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
        Schema::create('heart_rate_zones', function (Blueprint $table): void {
            $table->id();
            $table->string('sport', 32)->unique();
            $table->string('training_method')->nullable();
            $table->unsignedSmallInteger('max_hr')->nullable();
            $table->unsignedSmallInteger('resting_hr')->nullable();
            $table->unsignedSmallInteger('lactate_threshold_hr')->nullable();
            $table->unsignedSmallInteger('zone1_floor')->nullable();
            $table->unsignedSmallInteger('zone2_floor')->nullable();
            $table->unsignedSmallInteger('zone3_floor')->nullable();
            $table->unsignedSmallInteger('zone4_floor')->nullable();
            $table->unsignedSmallInteger('zone5_floor')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('heart_rate_zones');
    }
};
