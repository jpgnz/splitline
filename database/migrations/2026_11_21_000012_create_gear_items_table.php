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
        Schema::create('gear_items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('type', 32)->index();
            $table->string('default_sport', 32)->nullable()->index();
            $table->double('initial_distance_meters')->default(0);
            $table->double('max_distance_meters')->nullable();
            $table->string('purchased_at', 10)->nullable();
            $table->string('retired_at', 10)->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gear_items');
    }
};
