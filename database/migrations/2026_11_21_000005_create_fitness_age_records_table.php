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
        Schema::create('fitness_age_records', function (Blueprint $table): void {
            $table->id();
            $table->string('calendar_date', 10)->unique();
            $table->double('chronological_age')->nullable();
            $table->double('fitness_age')->nullable();
            $table->double('previous_fitness_age')->nullable();
            $table->json('components')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fitness_age_records');
    }
};
