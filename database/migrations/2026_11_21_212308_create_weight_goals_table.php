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
        Schema::create('weight_goals', function (Blueprint $table): void {
            $table->id();
            $table->string('start_date', 10)->unique();
            $table->string('end_date', 10)->nullable()->index();
            $table->unsignedInteger('goal_grams');
            $table->string('source_category')->nullable();
            $table->dateTime('garmin_updated_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weight_goals');
    }
};
