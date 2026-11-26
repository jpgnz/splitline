<?php

namespace App\Services\Heatmap;

use App\Models\Activity;
use App\Support\DateWindowResolver;
use App\Support\SportRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * @phpstan-type HeatmapTrack array{
 *     activity_id: int,
 *     name: string,
 *     sport_category: string,
 *     effective_discipline: ?string,
 *     sport_key: string,
 *     accent_hex: string,
 *     date: string,
 *     location_name: ?string,
 *     distance_meters: float,
 *     duration_seconds: float,
 *     elevation_gain: ?float,
 *     polyline: string
 * }
 * @phpstan-type HeatmapSummary array{
 *     track_count: int,
 *     total_distance_meters: float,
 *     total_elevation_gain: float,
 *     unsynced_gnss_count: int
 * }
 * @phpstan-type HeatmapPayload array{
 *     filters: array{
 *         sport: string,
 *         timeframe: string
 *     },
 *     order: 'newest_on_top'|'oldest_on_top'|'activity_type',
 *     dateWindow: array{
 *         range: string,
 *         start: ?string,
 *         end: string,
 *         from: ?string,
 *         to: ?string,
 *         prevEnd: ?string,
 *         nextEnd: ?string,
 *         isLatest: bool,
 *         label: string
 *     },
 *     available_sports: list<array{key: string, label: string, short_label: string, accent_hex: string}>,
 *     summary: HeatmapSummary,
 *     tracks: list<HeatmapTrack>
 * }
 */
class HeatmapService
{
    private const int MAX_TRACKS = 2500;

    /** @var list<string> */
    private const array VALID_TIMEFRAMES = ['30d', '12w', '6m', '1y', 'this_year', 'all', 'custom'];

    /** @var list<'newest_on_top'|'oldest_on_top'|'activity_type'> */
    private const array VALID_ORDERS = ['newest_on_top', 'oldest_on_top', 'activity_type'];

    public function __construct(
        private readonly ?SportRegistry $sports = null,
        private readonly ?DateWindowResolver $dateWindows = null,
    ) {}

    private function sportRegistry(): SportRegistry
    {
        return $this->sports ?? app(SportRegistry::class);
    }

    private function dateWindowResolver(): DateWindowResolver
    {
        return $this->dateWindows ?? app(DateWindowResolver::class);
    }

    /**
     * Build the GNSS track overlay payload for the personal route heatmap.
     *
     * @return HeatmapPayload
     */
    public function tracks(
        string $sport = 'all',
        string $timeframe = 'all',
        ?string $end = null,
        ?string $from = null,
        ?string $to = null,
        string $order = 'newest_on_top',
    ): array {
        $normalizedSport = $this->normalizeSport($sport);
        $normalizedOrder = $this->normalizeOrder($order);
        $timezone = (string) config('app.display_timezone', 'UTC');
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $dateWindow = $this->dateWindowResolver()->resolveDayWindow(
            $timeframe,
            $end,
            $from,
            $to,
            $today,
            self::VALID_TIMEFRAMES,
            'all',
        );
        $normalizedTimeframe = $dateWindow['range'];

        $baseQuery = Activity::query();
        $this->applySportFilter($baseQuery, $normalizedSport);
        $this->applyDateWindowFilter($baseQuery, $dateWindow);

        $unsyncedGnssCount = (clone $baseQuery)
            ->where('activities.is_indoor', false)
            ->where('activities.is_manual', false)
            ->awaitingStreams()
            ->count();

        $rows = (clone $baseQuery)
            ->join('activity_streams', 'activity_streams.activity_id', '=', 'activities.id')
            ->whereNotNull('activity_streams.polyline')
            ->select([
                'activities.id',
                'activities.name',
                'activities.sport_category',
                'activities.sport_discipline',
                'activities.discipline_override',
                'activities.is_indoor',
                'activities.started_at_local',
                'activities.location_name',
                'activities.distance_meters',
                'activities.duration_seconds',
                'activities.elevation_gain',
                'activity_streams.polyline',
            ])
            ->orderByDesc('activities.started_at_local')
            ->orderByDesc('activities.id')
            ->limit(self::MAX_TRACKS)
            ->toBase()
            ->cursor();

        $registry = $this->sportRegistry();
        $catalog = $registry->catalog();
        $tracks = [];
        $totalDistanceMeters = 0.0;
        $totalElevationGain = 0.0;

        foreach ($rows as $row) {
            $rawPolyline = is_string($row->polyline)
                ? json_decode($row->polyline, true)
                : $row->polyline;

            $polyline = $this->normalizePolyline($rawPolyline);

            if (count($polyline) < 2) {
                continue;
            }

            $distance = is_numeric($row->distance_meters) ? (float) $row->distance_meters : 0.0;
            $duration = is_numeric($row->duration_seconds) ? (float) $row->duration_seconds : 0.0;
            $elevation = is_numeric($row->elevation_gain) ? (float) $row->elevation_gain : null;

            $totalDistanceMeters += $distance;
            $totalElevationGain += $elevation ?? 0.0;

            $disciplineOverride = is_string($row->discipline_override) ? $row->discipline_override : null;
            $sportDiscipline = is_string($row->sport_discipline) ? $row->sport_discipline : null;
            $effectiveDiscipline = $disciplineOverride ?? $sportDiscipline;
            $startedAt = is_string($row->started_at_local) ? substr($row->started_at_local, 0, 10) : '';

            $sportCategory = (string) $row->sport_category;
            $sportKey = match (true) {
                $sportCategory === 'running' && $effectiveDiscipline === 'trail' => 'trail_running',
                $sportCategory === 'running' && $effectiveDiscipline === 'track' => 'track_running',
                $sportCategory === 'running' => 'road_running',
                $sportCategory === 'biking' && $effectiveDiscipline === 'road' => 'road_cycling',
                $sportCategory === 'biking' && $effectiveDiscipline === 'gravel' => 'gravel_cycling',
                $sportCategory === 'biking' && $effectiveDiscipline === 'mtb' => 'mtb_cycling',
                $sportCategory === 'biking' && $effectiveDiscipline === 'ebike' => 'ebike_cycling',
                $sportCategory === 'biking' && $effectiveDiscipline === 'commute' => 'commute_cycling',
                $sportCategory === 'biking' => 'cycling',
                isset($catalog[$sportCategory]) => $sportCategory,
                default => 'other',
            };
            $accentHex = $catalog[$sportKey]['accent_hex'] ?? '#71717a';

            $tracks[] = [
                'activity_id' => (int) $row->id,
                'name' => (string) $row->name,
                'sport_category' => $sportCategory,
                'effective_discipline' => $effectiveDiscipline,
                'sport_key' => $sportKey,
                'accent_hex' => $accentHex,
                'date' => $startedAt,
                'location_name' => is_string($row->location_name) ? $row->location_name : null,
                'distance_meters' => round($distance, 1),
                'duration_seconds' => round($duration, 1),
                'elevation_gain' => $elevation !== null ? round($elevation, 1) : null,
                'polyline' => self::encodePolyline($polyline),
            ];
        }

        return [
            'filters' => [
                'sport' => $normalizedSport,
                'timeframe' => $normalizedTimeframe,
            ],
            'order' => $normalizedOrder,
            'dateWindow' => $dateWindow,
            'available_sports' => $registry->filterableSports(),
            'summary' => [
                'track_count' => count($tracks),
                'total_distance_meters' => round($totalDistanceMeters, 1),
                'total_elevation_gain' => round($totalElevationGain, 1),
                'unsynced_gnss_count' => $unsyncedGnssCount,
            ],
            'tracks' => $tracks,
        ];
    }

    /**
     * Encode latitude/longitude coordinate pairs using the Google Encoded Polyline algorithm (1e5 precision).
     *
     * @param  list<array{0: float, 1: float}>  $points
     */
    public static function encodePolyline(array $points): string
    {
        $encoded = '';
        $prevLat = 0;
        $prevLng = 0;

        foreach ($points as [$lat, $lng]) {
            $currLat = (int) round($lat * 100000);
            $currLng = (int) round($lng * 100000);

            $encoded .= self::encodeSignedValue($currLat - $prevLat);
            $encoded .= self::encodeSignedValue($currLng - $prevLng);

            $prevLat = $currLat;
            $prevLng = $currLng;
        }

        return $encoded;
    }

    /**
     * Decode a Google Encoded Polyline string back into latitude/longitude coordinate pairs.
     *
     * @return list<array{0: float, 1: float}>
     */
    public static function decodePolyline(string $encoded): array
    {
        $points = [];
        $index = 0;
        $length = strlen($encoded);
        $lat = 0;
        $lng = 0;

        while ($index < $length) {
            $result = 0;
            $shift = 0;

            do {
                $byte = ord($encoded[$index]) - 63;
                $index++;
                $result |= ($byte & 0x1F) << $shift;
                $shift += 5;
            } while ($byte >= 0x20 && $index < $length);

            $lat += ($result & 1) !== 0 ? ~($result >> 1) : ($result >> 1);

            $result = 0;
            $shift = 0;

            do {
                $byte = ord($encoded[$index]) - 63;
                $index++;
                $result |= ($byte & 0x1F) << $shift;
                $shift += 5;
            } while ($byte >= 0x20 && $index < $length);

            $lng += ($result & 1) !== 0 ? ~($result >> 1) : ($result >> 1);

            $points[] = [round($lat / 100000, 5), round($lng / 100000, 5)];
        }

        return $points;
    }

    private static function encodeSignedValue(int $value): string
    {
        $shifted = $value < 0 ? ~($value << 1) : ($value << 1);
        $chunk = '';

        while ($shifted >= 0x20) {
            $chunk .= chr((0x20 | ($shifted & 0x1F)) + 63);
            $shifted >>= 5;
        }

        $chunk .= chr(($shifted & 0x1F) + 63);

        return $chunk;
    }

    private function normalizeSport(string $sport): string
    {
        $trimmed = strtolower(trim($sport));

        if ($trimmed === '' || $trimmed === 'all') {
            return 'all';
        }

        $registry = $this->sportRegistry();
        $normalized = $registry->normalizeKey($trimmed);

        return $registry->get($normalized) !== null ? $trimmed : 'all';
    }

    /**
     * @return 'newest_on_top'|'oldest_on_top'|'activity_type'
     */
    private function normalizeOrder(string $order): string
    {
        return in_array($order, self::VALID_ORDERS, true) ? $order : 'newest_on_top';
    }

    /**
     * @param  Builder<Activity>  $query
     */
    private function applySportFilter(Builder $query, string $sport): void
    {
        if ($sport === 'all') {
            return;
        }

        $this->sportRegistry()->applyScope($query, $sport);
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
    private function applyDateWindowFilter(
        Builder $query,
        array $dateWindow,
    ): void {
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
     * @return list<array{0: float, 1: float}>
     */
    private function normalizePolyline(mixed $rawPolyline): array
    {
        if (! is_array($rawPolyline)) {
            return [];
        }

        $points = [];

        foreach ($rawPolyline as $point) {
            if (! is_array($point) || ! array_key_exists(0, $point) || ! array_key_exists(1, $point)) {
                continue;
            }

            $rawLat = $point[0];
            $rawLng = $point[1];

            if (! is_numeric($rawLat) || ! is_numeric($rawLng)) {
                continue;
            }

            $lat = (float) $rawLat;
            $lng = (float) $rawLng;

            if (! is_finite($lat) || ! is_finite($lng)) {
                continue;
            }

            if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
                continue;
            }

            $points[] = [round($lat, 5), round($lng, 5)];
        }

        return $points;
    }
}
