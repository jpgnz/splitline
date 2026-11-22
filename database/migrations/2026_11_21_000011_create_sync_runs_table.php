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
        Schema::create('sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 32);
            $table->string('status', 32)->index();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->string('cursor_date', 10)->nullable();
            $table->unsignedInteger('items_synced')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
