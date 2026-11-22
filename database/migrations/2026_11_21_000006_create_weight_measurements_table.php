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
        Schema::create('weight_measurements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('garmin_sample_pk')->unique();
            $table->string('calendar_date', 10)->index();
            $table->dateTime('measured_at')->nullable()->index();
            $table->unsignedInteger('weight_grams');
            $table->double('bmi')->nullable();
            $table->double('body_fat_pct')->nullable();
            $table->double('body_water_pct')->nullable();
            $table->unsignedInteger('bone_mass_grams')->nullable();
            $table->unsignedInteger('muscle_mass_grams')->nullable();
            $table->string('source_type')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weight_measurements');
    }
};
