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
        Schema::table('geocoded_places', function (Blueprint $table): void {
            $table->boolean('is_empty')->default(false)->after('provider_host');
        });

        Schema::table('route_clusters', function (Blueprint $table): void {
            $table->timestamp('geocode_attempted_at')->nullable()->after('geocoded_at');
        });

        Schema::table('activities', function (Blueprint $table): void {
            $table->timestamp('location_geocoded_at')->nullable()->after('location_name');
            $table->timestamp('geocode_attempted_at')->nullable()->after('location_geocoded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->dropColumn(['location_geocoded_at', 'geocode_attempted_at']);
        });

        Schema::table('route_clusters', function (Blueprint $table): void {
            $table->dropColumn('geocode_attempted_at');
        });

        Schema::table('geocoded_places', function (Blueprint $table): void {
            $table->dropColumn('is_empty');
        });
    }
};
