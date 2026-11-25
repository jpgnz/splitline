<?php

namespace App\Services\Garmin;

use App\Models\Activity;
use App\Models\ActivityStream;
use App\Services\Ingestion\TelemetryPersistenceService;
use Closure;
use Illuminate\Support\Facades\Storage;
use Splitline\Core\Data\ActivityStreamData;
use Splitline\Core\Data\ProviderContext;
use Splitline\Core\Exceptions\ArchiveUnavailableException;
use Splitline\Parsers\Fit\FitParser;
use Splitline\ProviderGarminPersonal\GarminPersonalProvider;

class ActivityStreamSyncService
{
    public const string ARCHIVE_DIRECTORY = 'garmin/fits';

    public function __construct(
        private readonly GarminPersonalProvider $provider,
        private readonly FitParser $parser,
        private readonly TelemetryPersistenceService $persistence,
        private readonly BestEffortCalculator $bestEffortCalculator,
    ) {}

    /**
     * Relative path on the local disk where an activity's raw FIT or ZIP archive is cached.
     */
    public function archivePath(Activity $activity): string
    {
        $provider = $this->resolveProvider($activity);

        if ($provider === GarminPersonalProvider::ID) {
            $identifier = $activity->garmin_activity_id ?? $activity->external_id;

            return self::ARCHIVE_DIRECTORY."/{$identifier}.zip";
        }

        $identifier = $activity->external_id ?? (string) $activity->id;
        $zipPath = "{$provider}/fits/{$identifier}.zip";

        if (Storage::disk('local')->exists($zipPath)) {
            return $zipPath;
        }

        $fitPath = "{$provider}/fits/{$identifier}.fit";

        if (Storage::disk('local')->exists($fitPath)) {
            return $fitPath;
        }

        return $fitPath;
    }

    /**
     * Determine whether a local FIT archive is already cached for the activity.
     */
    public function hasLocalArchive(Activity $activity): bool
    {
        return Storage::disk('local')->exists($this->archivePath($activity));
    }

    /**
     * Download (or load cached), parse, and persist an activity's FIT telemetry stream and Best Efforts.
     *
     * @param  (callable(float, string): void)|null  $onRateLimitWait
     */
    public function sync(
        Activity $activity,
        bool $forceDownload = false,
        ?callable $onRateLimitWait = null,
    ): ActivityStream {
        $bytes = $this->resolveArchiveBytes($activity, $forceDownload, $onRateLimitWait);
        $stream = $this->parseArchiveBytes($bytes);
        $extendedRawSummary = $this->provider->mapper()->extractExtendedSummaryFromRaw($activity->raw_summary);

        return $this->persistence->upsertStream($activity, $stream, $extendedRawSummary);
    }

    /**
     * Recalculate (or clear) Best Efforts for an activity offline after discipline changes.
     */
    public function recalculateBestEfforts(Activity $activity): int
    {
        $disk = Storage::disk('local');
        $archivePath = $this->archivePath($activity);

        if ($disk->exists($archivePath)) {
            $bytes = $disk->get($archivePath);

            if (is_string($bytes) && $bytes !== '') {
                $stream = $this->parseArchiveBytes($bytes);

                return $this->bestEffortCalculator->syncForActivity($activity, $stream->records);
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

    /**
     * @param  (callable(float, string): void)|null  $onRateLimitWait
     */
    private function resolveArchiveBytes(
        Activity $activity,
        bool $forceDownload,
        ?callable $onRateLimitWait = null,
    ): string {
        $disk = Storage::disk('local');
        $archivePath = $this->archivePath($activity);

        if (! $forceDownload && $disk->exists($archivePath)) {
            $cached = $disk->get($archivePath);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $provider = $this->resolveProvider($activity);
        $externalId = $this->resolveExternalId($activity);

        if ($provider === GarminPersonalProvider::ID && $externalId !== null) {
            $context = new ProviderContext(
                scopeKey: 'default',
                timezone: $this->timezone(),
                credentials: [
                    'api_key' => (string) config('services.garmin.key', ''),
                ],
                onRateLimitWait: $onRateLimitWait !== null ? Closure::fromCallable($onRateLimitWait) : null,
            );

            $archive = $this->provider->downloadActivityArchive($context, $externalId);
            $bytes = $archive['bytes'];
            $disk->put($archivePath, $bytes);

            return $bytes;
        }

        throw new ArchiveUnavailableException(
            message: "No local FIT archive is available for {$provider} activity #{$activity->id}.",
            statusCode: 404,
        );
    }

    private function parseArchiveBytes(string $bytes): ActivityStreamData
    {
        if (str_starts_with($bytes, "PK\x03\x04")) {
            return $this->parser->parseZip($bytes);
        }

        return $this->parser->parseBinary($bytes);
    }

    private function resolveProvider(Activity $activity): string
    {
        return $activity->provider !== ''
            ? $activity->provider
            : GarminPersonalProvider::ID;
    }

    private function resolveExternalId(Activity $activity): ?string
    {
        if ($activity->external_id !== null && $activity->external_id !== '') {
            return $activity->external_id;
        }

        if ($activity->garmin_activity_id !== null) {
            return (string) $activity->garmin_activity_id;
        }

        return null;
    }

    private function timezone(): string
    {
        $timezone = config('app.display_timezone', 'UTC');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }
}
