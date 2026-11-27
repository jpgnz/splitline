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
        Schema::table('route_clusters', function (Blueprint $table): void {
            $table->string('place_label', 160)->nullable()->after('name_override');
            $table->timestamp('geocoded_at')->nullable()->after('last_activity_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('route_clusters', function (Blueprint $table): void {
            $table->dropColumn(['place_label', 'geocoded_at']);
        });
    }
};
