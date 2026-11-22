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
        Schema::create('sleep_records', function (Blueprint $table): void {
            $table->id();
            $table->string('calendar_date', 10)->unique();
            $table->unsignedInteger('sleep_seconds')->nullable();
            $table->unsignedInteger('deep_seconds')->nullable();
            $table->unsignedInteger('light_seconds')->nullable();
            $table->unsignedInteger('rem_seconds')->nullable();
            $table->unsignedInteger('awake_seconds')->nullable();
            $table->unsignedInteger('nap_seconds')->nullable();
            $table->dateTime('sleep_start_local')->nullable();
            $table->dateTime('sleep_end_local')->nullable();
            $table->unsignedSmallInteger('sleep_score')->nullable();
            $table->unsignedSmallInteger('sleep_need_minutes')->nullable();
            $table->unsignedSmallInteger('next_sleep_need_minutes')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sleep_records');
    }
};
