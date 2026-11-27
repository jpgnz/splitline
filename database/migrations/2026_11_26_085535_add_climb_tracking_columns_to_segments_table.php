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
            $table->unsignedInteger('detection_count')->default(0)->after('effort_count');
            $table->boolean('is_sub_climb')->default(false)->after('detection_count');
            $table->boolean('is_climb_verified')->default(true)->after('is_sub_climb');

            $table->index(
                ['kind', 'is_climb_verified', 'effort_count'],
                'segments_visibility_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('segments', function (Blueprint $table): void {
            $table->dropIndex('segments_visibility_index');
            $table->dropColumn(['detection_count', 'is_sub_climb', 'is_climb_verified']);
        });
    }
};
