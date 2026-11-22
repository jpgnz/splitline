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
        Schema::table('daily_metrics', function (Blueprint $table): void {
            $table->unsignedInteger('day_duration_seconds')->nullable()->after('calendar_date');
        });

        DB::table('daily_metrics')
            ->select(['id', 'raw_payload'])
            ->whereNotNull('raw_payload')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $payload = json_decode((string) $row->raw_payload, true);

                    if (! is_array($payload)) {
                        continue;
                    }

                    $durationMs = $payload['durationInMilliseconds']
                        ?? ($payload['wellnessChronology']['totalDurationInMillis'] ?? null);

                    if (! is_numeric($durationMs)) {
                        continue;
                    }

                    $seconds = (int) round(((float) $durationMs) / 1000);

                    if ($seconds <= 0) {
                        continue;
                    }

                    DB::table('daily_metrics')
                        ->where('id', $row->id)
                        ->update(['day_duration_seconds' => $seconds]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_metrics', function (Blueprint $table): void {
            $table->dropColumn('day_duration_seconds');
        });
    }
};
