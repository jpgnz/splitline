<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('segment_detections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('segment_id')
                ->constrained('segments')
                ->cascadeOnDelete();
            $table->foreignId('activity_id')
                ->constrained('activities')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['segment_id', 'activity_id']);
            $table->index('activity_id');
        });

        $now = now();

        DB::table('segments')
            ->where('kind', 'climb')
            ->whereNotNull('source_activity_id')
            ->orderBy('id')
            ->select(['id', 'source_activity_id'])
            ->chunk(500, function ($segments) use ($now): void {
                DB::table('segment_detections')->insertOrIgnore(
                    $segments->map(static fn (object $segment): array => [
                        'segment_id' => $segment->id,
                        'activity_id' => $segment->source_activity_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all(),
                );
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('segment_detections');
    }
};
