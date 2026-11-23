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
        Schema::table('activities', function (Blueprint $table): void {
            $table->dateTime('streams_unavailable_at')->nullable()->after('streams_synced_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->dropIndex(['streams_unavailable_at']);
            $table->dropColumn('streams_unavailable_at');
        });
    }
};
