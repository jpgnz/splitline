<?php

namespace App\Services\Export;

use App\Models\Activity;
use App\Services\Garmin\ActivityStreamSyncService;
use App\Support\DateWindowResolver;
use App\Support\SportRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use XMLWriter;

/**
 * Handles live SQLite backups, streaming CSV/JSON activity exports, GPX 1.1 generation,
 * original FIT archive resolution, and data portability summary metrics.
 *
 * @phpstan-type GpxTrackPoint array{
 *     lat: float,
 *     lng: float,
 *     altitude: ?float,
 *     time: ?float,
 *     hr: ?int,
 *     cadence: ?int,
 *     power: ?int
 * }
 */
class DataExportService
{
    /**
     * @var list<string>
     */
    private const array VALID_SPORTS = [
        'all',
        'running',
        'road_running',
        'trail_running',
        'track_running',
        'treadmill_running',
        'cycling',
        'road_cycling',
        'gravel_cycling',
        'mtb_cycling',
        'indoor_cycling',
        'ebike_cycling',
        'commute_cycling',
        'biking',
        'road',
        'mtb',
        'biking_other',
        'walking',
        'hiking',
        'swimming',
        'cardio',
        'other',
    ];

    /**
     * @var list<string>
     */
    private const array VALID_RANGES = ['30d', '12w', '6m', '1y', 'this_year', 'last_year', 'all', 'custom'];

    /**
     * @var list<string>
     */
    public const array EXPORT_COLUMNS = [
        'id',
        'provider',
        'external_id',
        'name',
        'sport_category',
        'effective_discipline',
        'is_indoor',
        'started_at_local',
        'started_at_utc',
        'location_name',
        'distance_meters',
        'duration_seconds',
        'moving_duration_seconds',
        'elevation_gain',
        'elevation_loss',
        'avg_speed',
        'max_speed',
        'avg_hr',
        'max_hr',
        'avg_power',
        'normalized_power',
        'tss',
        'training_load',
        'calories',
        'avg_cadence',
        'is_favorite',
        'is_pr',
        'decoupling_pct',
        'efficiency_factor',
        'pacing_strategy',
        'weather_condition',
        'temperature_c',
        'wind_speed_kmh',
        'headwind_pct',
    ];

    /**
     * @var list<string>
     */
    private const array ARCHIVE_DIRECTORIES = [
        'garmin/fits',
        'fit_upload/fits',
    ];

    public function __construct(
        private readonly ActivityStreamSyncService $streamSync,
        private readonly SportRegistry $sportRegistry,
        private readonly DateWindowResolver $dateWindowResolver,
    ) {}

    /**
     * Create a WAL-safe compacted SQLite snapshot using native `VACUUM INTO`.
     *
     * @return array{path: string, filename: string, size_bytes: int}
     *
     * @throws RuntimeException
     */
    public function createSqliteBackupSnapshot(): array
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Live database backups are only supported for SQLite connections.');
        }

        $database = $connection->getDatabaseName();

        if (! $this->isFileBackedSqliteDatabase($database)) {
            throw new RuntimeException('Live SQLite backups cannot be created for an in-memory database.');
        }

        $tmpDirectory = storage_path('app/private/tmp');
        File::ensureDirectoryExists($tmpDirectory);

        $timestamp = CarbonImmutable::now($this->timezone())->format('Y-m-d-His');
        $filename = "splitline-backup-{$timestamp}.sqlite";
        $snapshotPath = $tmpDirectory.DIRECTORY_SEPARATOR.'splitline-backup-'.Str::uuid()->toString().'.sqlite';

        if (is_file($snapshotPath)) {
            @unlink($snapshotPath);
        }

        try {
            $connection->statement('VACUUM INTO ?', [$snapshotPath]);
        } catch (Throwable $exception) {
            if (is_file($snapshotPath)) {
                @unlink($snapshotPath);
            }

            throw new RuntimeException(
                'Failed to create SQLite backup snapshot: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        clearstatcache(true, $snapshotPath);
        $size = is_file($snapshotPath) ? filesize($snapshotPath) : false;

        if ($size === false) {
            throw new RuntimeException('SQLite backup snapshot could not be read after creation.');
        }

        return [
            'path' => $snapshotPath,
            'filename' => $filename,
            'size_bytes' => $size,
        ];
    }

    /**
     * Stream filtered activities as a downloadable RFC 4180 CSV response using constant memory.
     *
     * @param  array<string, mixed>  $filters
     */
    public function streamActivitiesCsv(array $filters = []): StreamedResponse
    {
        $query = $this->buildFilteredExportQuery($filters);
        $date = CarbonImmutable::now($this->timezone())->format('Y-m-d');
        $filename = "splitline-activities-{$date}.csv";

        return new StreamedResponse(function () use ($query): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, self::EXPORT_COLUMNS, ',', '"', '');

            foreach ($query->cursor() as $activity) {
                $row = $this->mapActivityExportRow($activity);
                $csvRow = [];

                foreach (self::EXPORT_COLUMNS as $column) {
                    $csvRow[] = $this->sanitizeCsvCell($row[$column]);
                }

                fputcsv($handle, $csvRow, ',', '"', '');
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Stream filtered activities as a downloadable JSON array response using constant memory.
     *
     * @param  array<string, mixed>  $filters
     */
    public function streamActivitiesJson(array $filters = []): StreamedResponse
    {
        $query = $this->buildFilteredExportQuery($filters);
        $date = CarbonImmutable::now($this->timezone())->format('Y-m-d');
        $filename = "splitline-activities-{$date}.json";

        return new StreamedResponse(function () use ($query): void {
            echo '[';
            $isFirst = true;

            foreach ($query->cursor() as $activity) {
                if (! $isFirst) {
                    echo ',';
                }

                $isFirst = false;

                echo json_encode(
                    $this->mapActivityExportRow($activity),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );
            }

            echo ']';
        }, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Build a GPX 1.1 XML document for an activity's GPS route and sensor telemetry.
     *
     * @return ?array{xml: string, filename: string}
     */
    public function buildActivityGpx(Activity $activity): ?array
    {
        $stream = $activity->relationLoaded('stream')
            ? $activity->stream
            : $activity->stream()->first();

        if ($stream === null) {
            return null;
        }

        $points = $this->extractTrackPoints($stream->series, $stream->polyline);

        if ($points === []) {
            return null;
        }

        $writer = new XMLWriter;
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->setIndent(true);
        $writer->setIndentString('  ');

        $writer->startElement('gpx');
        $writer->writeAttribute('version', '1.1');
        $writer->writeAttribute('creator', 'Splitline');
        $writer->writeAttribute('xmlns', 'http://www.topografix.com/GPX/1/1');
        $writer->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $writer->writeAttribute('xmlns:gpxtpx', 'http://www.garmin.com/xmlschemas/TrackPointExtension/v1');
        $writer->writeAttribute(
            'xsi:schemaLocation',
            'http://www.topografix.com/GPX/1/1 http://www.topografix.com/GPX/1/1/gpx.xsd '
            .'http://www.garmin.com/xmlschemas/TrackPointExtension/v1 http://www.garmin.com/xmlschemas/TrackPointExtensionv1.xsd',
        );

        $startedAtUtc = $activity->started_at_utc->utc();

        $writer->startElement('metadata');
        $writer->writeElement('name', $activity->name);
        $writer->writeElement('time', $startedAtUtc->format('Y-m-d\TH:i:s\Z'));
        $writer->endElement();

        $writer->startElement('trk');
        $writer->writeElement('name', $activity->name);
        $writer->writeElement('type', $activity->activity_type_key !== '' ? $activity->activity_type_key : $activity->sport_category);

        $writer->startElement('trkseg');

        foreach ($points as $point) {
            $writer->startElement('trkpt');
            $writer->writeAttribute('lat', sprintf('%.6f', $point['lat']));
            $writer->writeAttribute('lon', sprintf('%.6f', $point['lng']));

            if ($point['altitude'] !== null) {
                $writer->writeElement('ele', sprintf('%.1f', $point['altitude']));
            }

            if ($point['time'] !== null) {
                $pointTimestamp = $point['time'] > 1_000_000_000.0
                    ? CarbonImmutable::createFromTimestampUTC((int) round($point['time']))
                    : $startedAtUtc->addSeconds((int) round($point['time']));

                $writer->writeElement('time', $pointTimestamp->format('Y-m-d\TH:i:s\Z'));
            }

            if ($point['hr'] !== null || $point['cadence'] !== null || $point['power'] !== null) {
                $writer->startElement('extensions');

                if ($point['power'] !== null) {
                    $writer->writeElement('power', (string) $point['power']);
                }

                if ($point['hr'] !== null || $point['cadence'] !== null) {
                    $writer->startElement('gpxtpx:TrackPointExtension');

                    if ($point['hr'] !== null) {
                        $writer->writeElement('gpxtpx:hr', (string) $point['hr']);
                    }

                    if ($point['cadence'] !== null) {
                        $writer->writeElement('gpxtpx:cad', (string) $point['cadence']);
                    }

                    $writer->endElement();
                }

                $writer->endElement();
            }

            $writer->endElement();
        }

        $writer->endElement();
        $writer->endElement();
        $writer->endElement();
        $writer->endDocument();

        return [
            'xml' => $writer->outputMemory(),
            'filename' => $this->activitySlugFilename($activity, 'gpx'),
        ];
    }

    /**
     * Resolve the cached original FIT or ZIP archive for an activity on the local disk.
     *
     * @return ?array{disk_path: string, filename: string, content_type: string}
     */
    public function resolveOriginalArchive(Activity $activity): ?array
    {
        $diskPath = $this->streamSync->archivePath($activity);

        if (! Storage::disk('local')->exists($diskPath)) {
            return null;
        }

        $extension = strtolower(pathinfo($diskPath, PATHINFO_EXTENSION));
        $resolvedExtension = $extension !== '' ? $extension : 'fit';
        $contentType = $resolvedExtension === 'zip'
            ? 'application/zip'
            : 'application/octet-stream';

        return [
            'disk_path' => $diskPath,
            'filename' => $this->activitySlugFilename($activity, $resolvedExtension),
            'content_type' => $contentType,
        ];
    }

    /**
     * Summary payload for the Settings Data Portability card.
     *
     * @return array{
     *     is_sqlite: bool,
     *     database_size_bytes: ?int,
     *     activities_count: int,
     *     cached_archives_count: int,
     *     cached_archives_size_bytes: int
     * }
     */
    public function summaryPayload(): array
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $isSqlite = $connection->getDriverName() === 'sqlite'
            && $this->isFileBackedSqliteDatabase($database);

        $databaseSizeBytes = null;

        if ($isSqlite && is_file($database)) {
            clearstatcache(true, $database);
            $size = filesize($database);

            if ($size !== false) {
                $databaseSizeBytes = $size;
            }
        }

        $archiveStats = $this->computeCachedArchiveStats();

        return [
            'is_sqlite' => $isSqlite,
            'database_size_bytes' => $databaseSizeBytes,
            'activities_count' => Activity::query()->count(),
            'cached_archives_count' => $archiveStats['count'],
            'cached_archives_size_bytes' => $archiveStats['size_bytes'],
        ];
    }

    /**
     * Neutralize spreadsheet formula injection payloads while preserving pure negative numbers.
     */
    public function sanitizeCsvCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $string = is_string($value) ? $value : '';

        if ($string === '') {
            return '';
        }

        if (preg_match('/^-\d+(?:\.\d+)?$/', $string) === 1) {
            return $string;
        }

        $firstChar = $string[0];

        if (in_array($firstChar, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$string;
        }

        return $string;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Activity>
     */
    private function buildFilteredExportQuery(array $filters): Builder
    {
        $timezone = $this->timezone();
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $search = $this->normalizeSearch($filters['search'] ?? null);
        $sport = $this->normalizeChoice($filters['sport'] ?? null, self::VALID_SPORTS, 'all');
        $rawRange = is_string($filters['range'] ?? null) ? $filters['range'] : null;
        $rawEnd = is_string($filters['end'] ?? null) ? $filters['end'] : null;
        $rawFrom = is_string($filters['from'] ?? null) ? $filters['from'] : null;
        $rawTo = is_string($filters['to'] ?? null) ? $filters['to'] : null;

        $dateWindow = $this->dateWindowResolver->resolveDayWindow(
            $rawRange,
            $rawEnd,
            $rawFrom,
            $rawTo,
            $today,
            self::VALID_RANGES,
            'all',
        );

        $pr = $this->normalizeBool($filters['pr'] ?? false);
        $favorite = $this->normalizeBool($filters['favorite'] ?? false);

        $query = Activity::query()
            ->select([
                'activities.id',
                'activities.provider',
                'activities.external_id',
                'activities.name',
                'activities.sport_category',
                'activities.sport_discipline',
                'activities.discipline_override',
                'activities.is_indoor',
                'activities.started_at_local',
                'activities.started_at_utc',
                'activities.location_name',
                'activities.distance_meters',
                'activities.duration_seconds',
                'activities.moving_duration_seconds',
                'activities.elevation_gain',
                'activities.elevation_loss',
                'activities.avg_speed',
                'activities.max_speed',
                'activities.avg_hr',
                'activities.max_hr',
                'activities.avg_power',
                'activities.normalized_power',
                'activities.tss',
                'activities.training_load',
                'activities.calories',
                'activities.avg_cadence',
                'activities.is_favorite',
                'activities.is_pr',
                'activity_derived_metrics.decoupling_pct as export_decoupling_pct',
                'activity_derived_metrics.efficiency_factor as export_efficiency_factor',
                'activity_derived_metrics.pacing_strategy as export_pacing_strategy',
                'activity_weathers.condition as export_weather_condition',
                'activity_weathers.temperature_c as export_temperature_c',
                'activity_weathers.wind_speed_kmh as export_wind_speed_kmh',
                'activity_weathers.headwind_pct as export_headwind_pct',
            ])
            ->leftJoin('activity_derived_metrics', 'activity_derived_metrics.activity_id', '=', 'activities.id')
            ->leftJoin('activity_weathers', 'activity_weathers.activity_id', '=', 'activities.id');

        $this->applyFacetFilters($query, $search, $sport, $pr, $favorite);
        $this->applyDateWindowFilter($query, $dateWindow);

        return $query
            ->orderByDesc('activities.started_at_local')
            ->orderByDesc('activities.id');
    }

    /**
     * @param  Builder<Activity>  $query
     */
    private function applyFacetFilters(
        Builder $query,
        ?string $search,
        string $sport,
        bool $pr,
        bool $favorite,
    ): void {
        if ($search !== null) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search));
            $pattern = '%'.$escaped.'%';

            $query->where(function (Builder $subQuery) use ($pattern): void {
                $subQuery
                    ->whereRaw("LOWER(activities.name) LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(activities.location_name, '')) LIKE ? ESCAPE '\\'", [$pattern]);
            });
        }

        if ($sport === 'biking_other') {
            $query->where('activities.sport_category', 'biking')
                ->whereRaw('COALESCE(activities.discipline_override, activities.sport_discipline) = ?', ['other']);
        } elseif ($sport !== 'all') {
            $this->sportRegistry->applyScope($query, $sport);
        }

        if ($pr) {
            $query->where('activities.is_pr', true);
        }

        if ($favorite) {
            $query->where('activities.is_favorite', true);
        }
    }

    /**
     * @param  Builder<Activity>  $query
     * @param  array{
     *     range: string,
     *     start: ?string,
     *     end: string,
     *     from: ?string,
     *     to: ?string
     * }  $dateWindow
     */
    private function applyDateWindowFilter(Builder $query, array $dateWindow): void
    {
        if ($dateWindow['range'] === 'all') {
            return;
        }

        if ($dateWindow['range'] === 'custom') {
            if ($dateWindow['from'] !== null) {
                $query->where('activities.started_at_local', '>=', $dateWindow['from'].' 00:00:00');
            }

            if ($dateWindow['to'] !== null) {
                $query->where('activities.started_at_local', '<=', $dateWindow['to'].' 23:59:59');
            }

            return;
        }

        if ($dateWindow['start'] !== null) {
            $query->whereBetween('activities.started_at_local', [
                $dateWindow['start'].' 00:00:00',
                $dateWindow['end'].' 23:59:59',
            ]);
        }
    }

    /**
     * @return array<string, int|float|string|bool|null>
     */
    private function mapActivityExportRow(Activity $activity): array
    {
        $decouplingPct = $activity->getAttribute('export_decoupling_pct');
        $efficiencyFactor = $activity->getAttribute('export_efficiency_factor');
        $pacingStrategy = $activity->getAttribute('export_pacing_strategy');
        $weatherCondition = $activity->getAttribute('export_weather_condition');
        $temperatureC = $activity->getAttribute('export_temperature_c');
        $windSpeedKmh = $activity->getAttribute('export_wind_speed_kmh');
        $headwindPct = $activity->getAttribute('export_headwind_pct');

        return [
            'id' => $activity->id,
            'provider' => $activity->provider,
            'external_id' => $activity->external_id,
            'name' => $activity->name,
            'sport_category' => $activity->sport_category,
            'effective_discipline' => $activity->effectiveDiscipline(),
            'is_indoor' => $activity->is_indoor,
            'started_at_local' => $activity->started_at_local->format('Y-m-d H:i:s'),
            'started_at_utc' => $activity->started_at_utc->utc()->toIso8601String(),
            'location_name' => $activity->location_name,
            'distance_meters' => $activity->distance_meters,
            'duration_seconds' => $activity->duration_seconds,
            'moving_duration_seconds' => $activity->moving_duration_seconds,
            'elevation_gain' => $activity->elevation_gain,
            'elevation_loss' => $activity->elevation_loss,
            'avg_speed' => $activity->avg_speed,
            'max_speed' => $activity->max_speed,
            'avg_hr' => $activity->avg_hr,
            'max_hr' => $activity->max_hr,
            'avg_power' => $activity->avg_power,
            'normalized_power' => $activity->normalized_power,
            'tss' => $activity->tss,
            'training_load' => $activity->training_load,
            'calories' => $activity->calories,
            'avg_cadence' => $activity->avg_cadence,
            'is_favorite' => $activity->is_favorite,
            'is_pr' => $activity->is_pr,
            'decoupling_pct' => is_numeric($decouplingPct) ? (float) $decouplingPct : null,
            'efficiency_factor' => is_numeric($efficiencyFactor) ? (float) $efficiencyFactor : null,
            'pacing_strategy' => is_string($pacingStrategy) && $pacingStrategy !== '' ? $pacingStrategy : null,
            'weather_condition' => is_string($weatherCondition) && $weatherCondition !== '' ? $weatherCondition : null,
            'temperature_c' => is_numeric($temperatureC) ? (float) $temperatureC : null,
            'wind_speed_kmh' => is_numeric($windSpeedKmh) ? (float) $windSpeedKmh : null,
            'headwind_pct' => is_numeric($headwindPct) ? (float) $headwindPct : null,
        ];
    }

    /**
     * Extract ordered GNSS track points from `series` (row-oriented or columnar) with `polyline` fallback.
     *
     * @param  array<int|string, mixed>|null  $series
     * @param  array<int|string, mixed>|null  $polyline
     * @return list<GpxTrackPoint>
     */
    private function extractTrackPoints(?array $series, ?array $polyline): array
    {
        if (is_array($series) && $series !== []) {
            $seriesPoints = $this->extractTrackPointsFromSeries($series);

            if ($seriesPoints !== []) {
                return $seriesPoints;
            }
        }

        if (! is_array($polyline) || $polyline === []) {
            return [];
        }

        $points = [];

        foreach ($polyline as $pair) {
            if (! is_array($pair) || ! isset($pair[0], $pair[1])) {
                continue;
            }

            $coords = $this->parseValidCoordinates($pair[0], $pair[1]);

            if ($coords === null) {
                continue;
            }

            $points[] = [
                'lat' => $coords[0],
                'lng' => $coords[1],
                'altitude' => null,
                'time' => null,
                'hr' => null,
                'cadence' => null,
                'power' => null,
            ];
        }

        return $points;
    }

    /**
     * @param  array<int|string, mixed>  $series
     * @return list<GpxTrackPoint>
     */
    private function extractTrackPointsFromSeries(array $series): array
    {
        $points = [];

        if (isset($series['lat'], $series['lng']) && is_array($series['lat']) && is_array($series['lng'])) {
            $lats = $series['lat'];
            $lngs = $series['lng'];
            $altitudes = is_array($series['altitude'] ?? null) ? $series['altitude'] : [];
            $times = is_array($series['time'] ?? null) ? $series['time'] : [];
            $hrs = is_array($series['hr'] ?? null) ? $series['hr'] : [];
            $cadences = is_array($series['cadence'] ?? null) ? $series['cadence'] : [];
            $powers = is_array($series['power'] ?? null) ? $series['power'] : [];

            foreach ($lats as $index => $rawLat) {
                $coords = $this->parseValidCoordinates($rawLat, $lngs[$index] ?? null);

                if ($coords === null) {
                    continue;
                }

                $points[] = [
                    'lat' => $coords[0],
                    'lng' => $coords[1],
                    'altitude' => $this->toNullableFloat($altitudes[$index] ?? null),
                    'time' => $this->toNullableFloat($times[$index] ?? null),
                    'hr' => $this->toNullableInt($hrs[$index] ?? null),
                    'cadence' => $this->toNullableInt($cadences[$index] ?? null),
                    'power' => $this->toNullableInt($powers[$index] ?? null),
                ];
            }

            return $points;
        }

        foreach ($series as $row) {
            if (! is_array($row)) {
                continue;
            }

            $coords = $this->parseValidCoordinates($row['lat'] ?? null, $row['lng'] ?? null);

            if ($coords === null) {
                continue;
            }

            $points[] = [
                'lat' => $coords[0],
                'lng' => $coords[1],
                'altitude' => $this->toNullableFloat($row['altitude'] ?? null),
                'time' => $this->toNullableFloat($row['time'] ?? null),
                'hr' => $this->toNullableInt($row['hr'] ?? null),
                'cadence' => $this->toNullableInt($row['cadence'] ?? null),
                'power' => $this->toNullableInt($row['power'] ?? null),
            ];
        }

        return $points;
    }

    /**
     * @return ?array{0: float, 1: float}
     */
    private function parseValidCoordinates(mixed $rawLat, mixed $rawLng): ?array
    {
        if (! is_numeric($rawLat) || ! is_numeric($rawLng)) {
            return null;
        }

        $lat = (float) $rawLat;
        $lng = (float) $rawLng;

        if (
            ! is_finite($lat)
            || ! is_finite($lng)
            || $lat < -90.0
            || $lat > 90.0
            || $lng < -180.0
            || $lng > 180.0
        ) {
            return null;
        }

        return [$lat, $lng];
    }

    private function toNullableFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return is_finite($float) ? $float : null;
    }

    private function toNullableInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return is_finite($float) ? (int) round($float) : null;
    }

    private function activitySlugFilename(Activity $activity, string $extension): string
    {
        $date = $activity->started_at_local->format('Y-m-d');
        $slug = Str::slug($activity->name);

        if ($slug === '') {
            $slug = 'activity-'.$activity->id;
        }

        return "{$date}-{$slug}.{$extension}";
    }

    private function isFileBackedSqliteDatabase(string $database): bool
    {
        $trimmed = trim($database);

        if ($trimmed === '' || $trimmed === ':memory:') {
            return false;
        }

        if (str_contains(strtolower($trimmed), 'mode=memory')) {
            return false;
        }

        return true;
    }

    /**
     * @return array{count: int, size_bytes: int}
     */
    private function computeCachedArchiveStats(): array
    {
        $disk = Storage::disk('local');
        $count = 0;
        $sizeBytes = 0;

        foreach (self::ARCHIVE_DIRECTORIES as $directory) {
            if (! $disk->directoryExists($directory)) {
                continue;
            }

            foreach ($disk->allFiles($directory) as $filePath) {
                $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

                if (! in_array($extension, ['fit', 'zip'], true)) {
                    continue;
                }

                $count++;
                $size = $disk->size($filePath);

                if ($size > 0) {
                    $sizeBytes += $size;
                }
            }
        }

        return [
            'count' => $count,
            'size_bytes' => $sizeBytes,
        ];
    }

    private function normalizeSearch(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function normalizeChoice(mixed $value, array $allowed, string $default): string
    {
        if (is_string($value) && in_array($value, $allowed, true)) {
            return $value;
        }

        return $default;
    }

    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return false;
    }

    private function timezone(): string
    {
        $timezone = config('app.display_timezone', 'UTC');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }
}
