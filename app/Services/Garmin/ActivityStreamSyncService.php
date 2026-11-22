<?php

namespace App\Services\Garmin;

use App\Jobs\SyncActivityWeatherJob;
use App\Models\Activity;
use App\Models\ActivityStream;
use App\Models\ActivityWeather;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

class ActivityStreamSyncService
{
    public const string ARCHIVE_DIRECTORY = 'garmin/fits';

    public function __construct(
        private readonly GarminClient $client,
        private readonly FitParser $parser,
        private readonly BestEffortCalculator $bestEffortCalculator,
    ) {}

    /**
     * Relative path on the local disk where an activity's raw Garmin FIT ZIP archive is cached.
     */
    public function archivePath(Activity $activity): string
    {
        return self::ARCHIVE_DIRECTORY."/{$activity->garmin_activity_id}.zip";
    }

    /**
     * Determine whether a local FIT ZIP archive is already cached for the activity.
     */
    public function hasLocalArchive(Activity $activity): bool
    {
        return Storage::disk('local')->exists($this->archivePath($activity));
    }

    /**
     * Download (or load cached), parse, and persist an activity's FIT telemetry stream and Best Efforts.
     */
    public function sync(Activity $activity, bool $forceDownload = false): ActivityStream
    {
        $zipBytes = $this->resolveArchiveBytes($activity, $forceDownload);
        $parsed = $this->parser->parseZip($zipBytes);

        $stream = ActivityStream::query()->updateOrCreate(
            ['activity_id' => $activity->id],
            [
                'series' => $parsed['series'],
                'polyline' => $parsed['polyline'],
                'laps' => $parsed['laps'],
                'km_splits' => $parsed['km_splits'],
                'run_walk_segments' => $parsed['run_walk_segments'] !== [] ? $parsed['run_walk_segments'] : null,
                'jumps' => $parsed['jumps'] !== [] ? $parsed['jumps'] : null,
            ],
        );

        $this->bestEffortCalculator->syncForActivity($activity, $parsed['records']);

        $activityUpdates = [
            'streams_synced_at' => CarbonImmutable::now(),
        ];

        $rawSummaryUpdates = $this->extractExtendedSummaryFromRaw($activity->raw_summary);

        foreach ($rawSummaryUpdates as $column => $value) {
            if ($value !== null) {
                $activityUpdates[$column] = $value;
            }
        }

        foreach ($parsed['session_summary'] as $column => $value) {
            if ($value === null) {
                continue;
            }

            if ($column === 'total_work' && $activity->total_work !== null) {
                continue;
            }

            $activityUpdates[$column] = $value;
        }

        if ($parsed['polyline'] !== []) {
            if ($activity->start_lat === null) {
                $activityUpdates['start_lat'] = $parsed['polyline'][0][0];
            }

            if ($activity->start_lng === null) {
                $activityUpdates['start_lng'] = $parsed['polyline'][0][1];
            }
        }

        $activity->update($activityUpdates);

        if (
            ! $activity->is_indoor
            && ($activity->start_lat !== null && $activity->start_lng !== null || $parsed['polyline'] !== [])
            && (bool) config('services.open_meteo.enabled', true)
            && (bool) config('services.open_meteo.auto_sync', true)
        ) {
            $existingSamplingMode = ActivityWeather::query()
                ->where('activity_id', $activity->id)
                ->value('sampling_mode');

            if ($existingSamplingMode === null || $existingSamplingMode === 'start_point') {
                SyncActivityWeatherJob::dispatch($activity->id, force: $existingSamplingMode === 'start_point');
            }
        }

        return $stream;
    }

    /**
     * Backfill extended summary columns already present in stored Garmin raw_summary JSON.
     *
     * @param  array<int|string, mixed>|null  $raw
     * @return array<string, mixed>
     */
    private function extractExtendedSummaryFromRaw(?array $raw): array
    {
        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $updates = [
            'avg_grade_adjusted_speed' => is_numeric($raw['avgGradeAdjustedSpeed'] ?? null) ? (float) $raw['avgGradeAdjustedSpeed'] : null,
            'bmr_calories' => is_numeric($raw['bmrCalories'] ?? null) ? (int) round((float) $raw['bmrCalories']) : null,
            'sweat_loss_ml' => is_numeric($raw['waterEstimated'] ?? null) ? (int) round((float) $raw['waterEstimated']) : null,
            'avg_vertical_oscillation' => is_numeric($raw['avgVerticalOscillation'] ?? null) ? (float) $raw['avgVerticalOscillation'] : null,
            'avg_vertical_ratio' => is_numeric($raw['avgVerticalRatio'] ?? null) ? (float) $raw['avgVerticalRatio'] : null,
            'avg_ground_contact_time' => is_numeric($raw['avgGroundContactTime'] ?? null) ? (float) $raw['avgGroundContactTime'] : null,
            'aerobic_te_message' => is_string($raw['aerobicTrainingEffectMessage'] ?? null) ? $raw['aerobicTrainingEffectMessage'] : null,
            'anaerobic_te_message' => is_string($raw['anaerobicTrainingEffectMessage'] ?? null) ? $raw['anaerobicTrainingEffectMessage'] : null,
            'avg_respiration_rate' => is_numeric($raw['avgRespirationRate'] ?? null) ? (float) $raw['avgRespirationRate'] : null,
            'min_respiration_rate' => is_numeric($raw['minRespirationRate'] ?? null) ? (float) $raw['minRespirationRate'] : null,
            'max_respiration_rate' => is_numeric($raw['maxRespirationRate'] ?? null) ? (float) $raw['maxRespirationRate'] : null,
            'min_temperature' => is_numeric($raw['minTemperature'] ?? null) ? (float) $raw['minTemperature'] : null,
            'max_temperature' => is_numeric($raw['maxTemperature'] ?? null) ? (float) $raw['maxTemperature'] : null,
            'moderate_intensity_minutes' => is_numeric($raw['moderateIntensityMinutes'] ?? null) ? (int) round((float) $raw['moderateIntensityMinutes']) : null,
            'vigorous_intensity_minutes' => is_numeric($raw['vigorousIntensityMinutes'] ?? null) ? (int) round((float) $raw['vigorousIntensityMinutes']) : null,
        ];

        if (isset($raw['splitSummaries']) && is_array($raw['splitSummaries'])) {
            foreach ($raw['splitSummaries'] as $split) {
                if (! is_array($split) || ! is_string($split['splitType'] ?? null) || ! is_numeric($split['duration'] ?? null)) {
                    continue;
                }

                $duration = round((float) $split['duration'], 2);

                if ($split['splitType'] === 'RWD_RUN') {
                    $updates['run_duration_seconds'] = $duration;
                } elseif ($split['splitType'] === 'RWD_WALK') {
                    $updates['walk_duration_seconds'] = $duration;
                } elseif ($split['splitType'] === 'RWD_STAND') {
                    $updates['idle_duration_seconds'] = $duration;
                }
            }
        }

        return $updates;
    }

    /**
     * Recalculate (or clear) Best Efforts for an activity offline after discipline changes.
     */
    public function recalculateBestEfforts(Activity $activity): int
    {
        $disk = Storage::disk('local');
        $archivePath = $this->archivePath($activity);

        if ($disk->exists($archivePath)) {
            $zipBytes = $disk->get($archivePath);

            if (is_string($zipBytes) && $zipBytes !== '') {
                $parsed = $this->parser->parseZip($zipBytes);

                return $this->bestEffortCalculator->syncForActivity($activity, $parsed['records']);
            }
        }

        $stream = $activity->relationLoaded('stream')
            ? $activity->stream
            : $activity->stream()->first();

        if ($stream === null || ! is_array($stream->series) || $stream->series === []) {
            return $this->bestEffortCalculator->syncForActivity($activity, []);
        }

        $records = [];

        foreach ($stream->series as $point) {
            if (! is_array($point) || ! isset($point['time']) || ! is_numeric($point['time'])) {
                continue;
            }

            $records[] = [
                'time' => (float) $point['time'],
                'distance' => isset($point['distance']) && is_numeric($point['distance']) ? (float) $point['distance'] : null,
                'altitude' => isset($point['altitude']) && is_numeric($point['altitude']) ? (float) $point['altitude'] : null,
                'speed' => isset($point['speed']) && is_numeric($point['speed']) ? (float) $point['speed'] : null,
                'hr' => isset($point['hr']) && is_numeric($point['hr']) ? (int) $point['hr'] : null,
                'cadence' => isset($point['cadence']) && is_numeric($point['cadence']) ? (int) $point['cadence'] : null,
                'power' => isset($point['power']) && is_numeric($point['power']) ? (int) $point['power'] : null,
                'lat' => isset($point['lat']) && is_numeric($point['lat']) ? (float) $point['lat'] : null,
                'lng' => isset($point['lng']) && is_numeric($point['lng']) ? (float) $point['lng'] : null,
            ];
        }

        if ($records === []) {
            return 0;
        }

        return $this->bestEffortCalculator->syncForActivity($activity, $records);
    }

    private function resolveArchiveBytes(Activity $activity, bool $forceDownload): string
    {
        $disk = Storage::disk('local');
        $archivePath = $this->archivePath($activity);

        if (! $forceDownload && $disk->exists($archivePath)) {
            $cached = $disk->get($archivePath);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $zipBytes = $this->client->downloadActivity($activity->garmin_activity_id);
        $disk->put($archivePath, $zipBytes);

        return $zipBytes;
    }
}
