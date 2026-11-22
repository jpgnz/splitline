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
        Schema::create('hrv_records', function (Blueprint $table): void {
            $table->id();
            $table->string('calendar_date', 10)->unique();
            $table->unsignedSmallInteger('weekly_avg')->nullable();
            $table->unsignedSmallInteger('last_night_avg')->nullable();
            $table->unsignedSmallInteger('last_night_5min_high')->nullable();
            $table->string('status')->nullable();
            $table->unsignedSmallInteger('baseline_low_upper')->nullable();
            $table->unsignedSmallInteger('baseline_balanced_low')->nullable();
            $table->unsignedSmallInteger('baseline_balanced_upper')->nullable();
            $table->string('feedback_phrase')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hrv_records');
    }
};
