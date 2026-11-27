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
            $table->dateTime('route_match_skipped_at')->nullable()->after('streams_unavailable_at')->index();
        });

        Schema::table('activity_route_fingerprints', function (Blueprint $table): void {
            $table->string('preserved_name_override', 160)->nullable()->after('is_unlinked');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_route_fingerprints', function (Blueprint $table): void {
            $table->dropColumn('preserved_name_override');
        });

        Schema::table('activities', function (Blueprint $table): void {
            $table->dropIndex(['route_match_skipped_at']);
            $table->dropColumn('route_match_skipped_at');
        });
    }
};
