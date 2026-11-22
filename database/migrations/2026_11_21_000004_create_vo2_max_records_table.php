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
        Schema::create('vo2_max_records', function (Blueprint $table): void {
            $table->id();
            $table->string('calendar_date', 10)->index();
            $table->string('sport', 32);
            $table->double('vo2_max_precise')->nullable();
            $table->double('vo2_max')->nullable();
            $table->string('sub_sport')->nullable();
            $table->timestamps();

            $table->unique(['calendar_date', 'sport']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vo2_max_records');
    }
};
