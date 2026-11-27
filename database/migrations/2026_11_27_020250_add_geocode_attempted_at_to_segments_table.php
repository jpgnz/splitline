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
        Schema::table('segments', function (Blueprint $table): void {
            $table->timestamp('geocode_attempted_at')->nullable()->after('geocoded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('segments', function (Blueprint $table): void {
            $table->dropColumn('geocode_attempted_at');
        });
    }
};
