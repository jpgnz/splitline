<?php

namespace App\Services\Geocoding;

use App\Models\GeocodedPlace;
use App\Services\Geocoding\Exceptions\GeocodingRateLimitedException;
use App\Support\AppVersion;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

/**
 * OSMF Nominatim Usage Policy compliant reverse geocoding client with two-tier SQLite spatial caching
 * and persistent global rate-limit spacing.
 */
class NominatimClient
{
    public const string PUBLIC_HOST = 'nominatim.openstreetmap.org';

    public const string DEFAULT_BASE_URL = 'https://nominatim.openstreetmap.org';

    public const int DEFAULT_RATE_LIMIT_MS = 1100;

    public const int PUBLIC_MIN_RATE_LIMIT_MS = 1000;

    public const int AREA_ZOOM = 14;

    public const float AREA_BUCKET_DEG = 0.002;

    public const int FEATURE_ZOOM = 17;

    public const float FEATURE_BUCKET_DEG = 0.00025;

    public const string CACHE_LAST_REQUEST_KEY = 'splitline.nominatim.last_request_at_ms';

    public const string CACHE_COOLDOWN_UNTIL_KEY = 'splitline.nominatim.cooldown_until_ms';

    /**
     * @var array<string, true>
     */
    private const array OUTDOOR_LEISURE_TYPES = [
        'park' => true,
        'nature_reserve' => true,
        'garden' => true,
        'track' => true,
        'pitch' => true,
        'recreation_ground' => true,
        'marina' => true,
        'playground' => true,
        'sports_centre' => true,
        'stadium' => true,
        'golf_course' => true,
        'common' => true,
        'dog_park' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const array OUTDOOR_NATURAL_TYPES = [
        'peak' => true,
        'beach' => true,
        'bay' => true,
        'wood' => true,
        'water' => true,
        'coastline' => true,
        'heath' => true,
        'ridge' => true,
        'saddle' => true,
        'cliff' => true,
        'cape' => true,
        'valley' => true,
        'wetland' => true,
        'scrub' => true,
        'grassland' => true,
        'glacier' => true,
        'volcano' => true,
        'hill' => true,
        'spring' => true,
        'peninsula' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const array OUTDOOR_HIGHWAY_TYPES = [
        'path' => true,
        'track' => true,
        'cycleway' => true,
        'bridleway' => true,
        'footway' => true,
        'steps' => true,
        'pedestrian' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const array OUTDOOR_TOURISM_TYPES = [
        'viewpoint' => true,
        'attraction' => true,
        'picnic_site' => true,
        'camp_site' => true,
        'alpine_hut' => true,
        'wilderness_hut' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const array UNNAMED_VALUES = [
        '' => true,
        'unnamed' => true,
        'unnamed road' => true,
        'unknown' => true,
    ];

    /**
     * @param  (Closure(int): void)|null  $sleeper  Optional microsecond sleeper (defaults to Laravel Sleep::usleep).
     * @param  (Closure(): float)|null  $clockMs  Optional millisecond epoch clock (defaults to microtime(true) * 1000).
     */
    public function __construct(
        private readonly AppVersion $appVersion,
        private readonly ?Closure $sleeper = null,
        private readonly ?Closure $clockMs = null,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('services.nominatim.enabled', true);
    }

    public function baseUrl(): string
    {
        $configured = config('services.nominatim.base_url', self::DEFAULT_BASE_URL);
        $trimmed = is_string($configured) ? rtrim(trim($configured), '/') : '';

        return $trimmed !== '' ? $trimmed : self::DEFAULT_BASE_URL;
    }

    public function providerHost(): string
    {
        $host = parse_url($this->baseUrl(), PHP_URL_HOST);

        return is_string($host) && trim($host) !== ''
            ? strtolower(trim($host))
            : self::PUBLIC_HOST;
    }

    public function isPublicHost(): bool
    {
        return $this->providerHost() === self::PUBLIC_HOST;
    }

    public function effectiveRateLimitMs(): int
    {
        $configured = max(0, (int) config('services.nominatim.rate_limit_ms', self::DEFAULT_RATE_LIMIT_MS));

        if ($this->isPublicHost()) {
            return max(self::PUBLIC_MIN_RATE_LIMIT_MS, $configured);
        }

        return $configured;
    }

    public function userAgent(): string
    {
        $configured = config('services.nominatim.user_agent');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $version = $this->appVersion->current();

        return "Splitline/{$version} (+https://github.com/splitline-php/splitline)";
    }

    public function referer(): ?string
    {
        $configured = config('services.nominatim.referer');
        $resolved = $this->validHttpUrl($configured);

        if ($resolved !== null) {
            return $resolved;
        }

        return $this->validHttpUrl(config('app.url'));
    }

    /**
     * Compute deterministic spatial cache buckets and zoom level for a precision tier.
     *
     * @return array{lat_bucket: int, lng_bucket: int, zoom: int}
     */
    public static function bucketCoordinates(float $lat, float $lng, string $tier): array
    {
        [$bucketDeg, $zoom] = match ($tier) {
            GeocodedPlace::TIER_AREA => [self::AREA_BUCKET_DEG, self::AREA_ZOOM],
            GeocodedPlace::TIER_FEATURE => [self::FEATURE_BUCKET_DEG, self::FEATURE_ZOOM],
            default => throw new InvalidArgumentException("Unsupported geocoding precision tier: {$tier}"),
        };

        return [
            'lat_bucket' => (int) round($lat / $bucketDeg),
            'lng_bucket' => (int) round($lng / $bucketDeg),
            'zoom' => $zoom,
        ];
    }

    /**
     * Reverse-geocode a coordinate using two-tier SQLite caching before making any HTTP call.
     *
     * @throws GeocodingRateLimitedException
     */
    public function reverse(
        float $lat,
        float $lng,
        string $tier = GeocodedPlace::TIER_AREA,
        bool $force = false,
    ): ?GeocodedPlace {
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

        $bucket = self::bucketCoordinates($lat, $lng, $tier);

        if (! $force) {
            $cached = GeocodedPlace::query()
                ->where('precision_tier', $tier)
                ->where('lat_bucket', $bucket['lat_bucket'])
                ->where('lng_bucket', $bucket['lng_bucket'])
                ->first();

            if ($cached instanceof GeocodedPlace) {
                return $cached;
            }
        }

        if (! $this->isEnabled()) {
            return null;
        }

        $payload = $this->requestReverse($lat, $lng, $bucket['zoom']);

        if ($payload === null) {
            return null;
        }

        $extracted = $this->extractPlaceAttributes($payload, $tier);

        return GeocodedPlace::query()->updateOrCreate(
            [
                'precision_tier' => $tier,
                'lat_bucket' => $bucket['lat_bucket'],
                'lng_bucket' => $bucket['lng_bucket'],
            ],
            [
                ...$extracted,
                'zoom' => $bucket['zoom'],
                'lat' => round($lat, 6),
                'lng' => round($lng, 6),
                'provider_host' => mb_substr($this->providerHost(), 0, 120),
                'fetched_at' => CarbonImmutable::now('UTC'),
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws GeocodingRateLimitedException
     */
    private function requestReverse(float $lat, float $lng, int $zoom): ?array
    {
        $maxRetries = max(0, (int) config('services.nominatim.max_retries', 2));
        $timeout = max(1, (int) config('services.nominatim.timeout', 15));
        $endpoint = $this->baseUrl().'/reverse';

        $headers = [
            'User-Agent' => $this->userAgent(),
            'Accept' => 'application/json',
            'Accept-Language' => 'en',
        ];

        $referer = $this->referer();

        if ($referer !== null) {
            $headers['Referer'] = $referer;
        }

        $query = [
            'format' => 'jsonv2',
            'lat' => number_format($lat, 6, '.', ''),
            'lon' => number_format($lng, 6, '.', ''),
            'zoom' => $zoom,
            'addressdetails' => 1,
            'namedetails' => 1,
        ];

        $email = config('services.nominatim.email');

        if (is_string($email) && trim($email) !== '') {
            $query['email'] = trim($email);
        }

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $this->enforceRateLimitAndCooldown();

            try {
                $response = Http::timeout($timeout)
                    ->withHeaders($headers)
                    ->get($endpoint, $query);
            } catch (ConnectionException $exception) {
                if ($attempt < $maxRetries) {
                    $this->sleepMicroseconds(($attempt + 1) * 500_000);

                    continue;
                }

                Log::warning('Nominatim reverse geocoding connection failed.', [
                    'host' => $this->providerHost(),
                    'message' => $exception->getMessage(),
                ]);

                return null;
            }

            $status = $response->status();

            if ($status === 429 || $status === 503) {
                $retryAfter = $this->parseRetryAfterSeconds($response, $status === 429 ? 60 : 30);
                $this->setCooldown($retryAfter);

                throw new GeocodingRateLimitedException(
                    "Nominatim reverse geocoding rate limited (HTTP {$status}). Retry after {$retryAfter}s.",
                    $retryAfter,
                );
            }

            if ($status >= 500 && $attempt < $maxRetries) {
                $this->sleepMicroseconds(($attempt + 1) * 500_000);

                continue;
            }

            if ($response->failed()) {
                Log::warning('Nominatim reverse geocoding returned error status.', [
                    'host' => $this->providerHost(),
                    'status' => $status,
                ]);

                return null;
            }

            $decoded = $response->json();

            if (! is_array($decoded) || isset($decoded['error'])) {
                return null;
            }

            /** @var array<string, mixed> $decoded */
            return $decoded;
        }

        return null;
    }

    /**
     * Enforce global cooldown and minimum request interval spacing.
     *
     * @throws GeocodingRateLimitedException
     */
    private function enforceRateLimitAndCooldown(): void
    {
        $nowMs = $this->nowMs();
        $cooldownUntil = Cache::get(self::CACHE_COOLDOWN_UNTIL_KEY);

        if (is_numeric($cooldownUntil) && (float) $cooldownUntil > $nowMs) {
            $remainingSeconds = max(1, (int) ceil(((float) $cooldownUntil - $nowMs) / 1000.0));

            throw new GeocodingRateLimitedException(
                "Nominatim rate-limit cooldown active. Retry after {$remainingSeconds}s.",
                $remainingSeconds,
            );
        }

        $minSpacingMs = $this->effectiveRateLimitMs();

        if ($minSpacingMs > 0) {
            $lastRequestAt = Cache::get(self::CACHE_LAST_REQUEST_KEY);

            if (is_numeric($lastRequestAt)) {
                $elapsedMs = $nowMs - (float) $lastRequestAt;

                if ($elapsedMs >= 0.0 && $elapsedMs < $minSpacingMs) {
                    $waitMs = $minSpacingMs - $elapsedMs;
                    $this->sleepMicroseconds((int) ceil($waitMs * 1000.0));
                    $nowMs = $this->nowMs();
                }
            }
        }

        Cache::put(self::CACHE_LAST_REQUEST_KEY, $nowMs, 120);
    }

    private function setCooldown(int $seconds): void
    {
        $clamped = max(1, min(3600, $seconds));
        $cooldownUntilMs = $this->nowMs() + ($clamped * 1000.0);

        Cache::put(self::CACHE_COOLDOWN_UNTIL_KEY, $cooldownUntilMs, max($clamped, 60));
    }

    private function parseRetryAfterSeconds(Response $response, int $defaultSeconds): int
    {
        $trimmed = trim($response->header('Retry-After'));

        if ($trimmed === '') {
            return $defaultSeconds;
        }

        if (ctype_digit($trimmed)) {
            return max(1, min(3600, (int) $trimmed));
        }

        $timestamp = strtotime($trimmed);

        if ($timestamp !== false) {
            $diff = $timestamp - (int) floor($this->nowMs() / 1000.0);

            return max(1, min(3600, $diff));
        }

        return $defaultSeconds;
    }

    private function nowMs(): float
    {
        if ($this->clockMs !== null) {
            return ($this->clockMs)();
        }

        return microtime(true) * 1000.0;
    }

    private function sleepMicroseconds(int $microseconds): void
    {
        if ($microseconds <= 0) {
            return;
        }

        if ($this->sleeper !== null) {
            ($this->sleeper)($microseconds);

            return;
        }

        Sleep::usleep($microseconds);
    }

    private function validHttpUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || filter_var($trimmed, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = parse_url($trimmed, PHP_URL_SCHEME);

        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Parse Nominatim jsonv2 payload into privacy-friendly outdoor place attributes without house_number.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     osm_type: ?string,
     *     osm_id: ?int,
     *     category: ?string,
     *     type: ?string,
     *     feature_name: ?string,
     *     road: ?string,
     *     neighbourhood: ?string,
     *     suburb: ?string,
     *     city: ?string,
     *     region: ?string,
     *     country: ?string,
     *     country_code: ?string,
     *     display_label: ?string,
     *     area_label: ?string,
     *     raw_address: ?array<string, mixed>
     * }
     */
    private function extractPlaceAttributes(array $payload, string $tier): array
    {
        $osmType = $this->cleanText($payload['osm_type'] ?? null, 16);
        $osmId = isset($payload['osm_id']) && is_numeric($payload['osm_id']) ? (int) $payload['osm_id'] : null;
        $category = $this->cleanText($payload['category'] ?? ($payload['class'] ?? null), 64);
        $type = $this->cleanText($payload['type'] ?? null, 64);

        /** @var array<string, mixed> $address */
        $address = is_array($payload['address'] ?? null) ? $payload['address'] : [];
        /** @var array<string, mixed> $nameDetails */
        $nameDetails = is_array($payload['namedetails'] ?? null) ? $payload['namedetails'] : [];

        $houseNumber = $this->cleanText($address['house_number'] ?? null, 64);

        $sanitizedAddress = $address;
        unset($sanitizedAddress['house_number']);

        $topLevelName = $this->cleanPlaceLabel(
            $nameDetails['name:en'] ?? ($nameDetails['name'] ?? ($payload['name'] ?? null)),
            $houseNumber,
        );

        $featureName = $this->resolveFeatureName($category, $type, $topLevelName, $sanitizedAddress, $houseNumber);

        $road = $this->firstCleanPlaceLabel([
            $sanitizedAddress['road'] ?? null,
            $sanitizedAddress['pedestrian'] ?? null,
            $sanitizedAddress['footway'] ?? null,
            $sanitizedAddress['cycleway'] ?? null,
            $sanitizedAddress['path'] ?? null,
            $sanitizedAddress['track'] ?? null,
            $sanitizedAddress['bridleway'] ?? null,
            $category === 'highway' ? $topLevelName : null,
        ], $houseNumber);

        $neighbourhood = $this->firstCleanPlaceLabel([
            $sanitizedAddress['neighbourhood'] ?? null,
            $sanitizedAddress['quarter'] ?? null,
            $sanitizedAddress['hamlet'] ?? null,
        ], $houseNumber);

        $suburb = $this->firstCleanPlaceLabel([
            $sanitizedAddress['suburb'] ?? null,
            $sanitizedAddress['city_district'] ?? null,
            $sanitizedAddress['borough'] ?? null,
        ], $houseNumber);

        $village = $this->cleanPlaceLabel($sanitizedAddress['village'] ?? null, $houseNumber);
        $town = $this->cleanPlaceLabel($sanitizedAddress['town'] ?? null, $houseNumber);
        $rawCity = $this->cleanPlaceLabel($sanitizedAddress['city'] ?? null, $houseNumber);
        $municipality = $this->cleanPlaceLabel($sanitizedAddress['municipality'] ?? null, $houseNumber);

        $city = $rawCity ?? $town ?? $village ?? $municipality;

        $region = $this->firstCleanPlaceLabel([
            $sanitizedAddress['region'] ?? null,
            $sanitizedAddress['state'] ?? null,
            $sanitizedAddress['county'] ?? null,
            $sanitizedAddress['province'] ?? null,
        ], $houseNumber);

        $country = $this->cleanText($sanitizedAddress['country'] ?? null, 120);
        $countryCodeRaw = $this->cleanText($sanitizedAddress['country_code'] ?? null, 8);
        $countryCode = $countryCodeRaw !== null ? strtolower($countryCodeRaw) : null;

        $areaLabel = $suburb ?? $neighbourhood ?? $village ?? $town ?? $city ?? $region;

        $displayLabel = $tier === GeocodedPlace::TIER_FEATURE
            ? ($featureName ?? $road ?? $neighbourhood ?? $suburb ?? $village ?? $town ?? $city ?? $region)
            : $areaLabel;

        return [
            'osm_type' => $osmType,
            'osm_id' => $osmId,
            'category' => $category,
            'type' => $type,
            'feature_name' => $featureName,
            'road' => $road,
            'neighbourhood' => $neighbourhood,
            'suburb' => $suburb,
            'city' => $city,
            'region' => $region,
            'country' => $country,
            'country_code' => $countryCode,
            'display_label' => $displayLabel,
            'area_label' => $areaLabel,
            'raw_address' => $sanitizedAddress !== [] ? $sanitizedAddress : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function resolveFeatureName(
        ?string $category,
        ?string $type,
        ?string $topLevelName,
        array $address,
        ?string $houseNumber,
    ): ?string {
        $normCategory = $category !== null ? strtolower($category) : '';
        $normType = $type !== null ? strtolower($type) : '';

        $isOutdoorFeature = ($normCategory === 'leisure' && isset(self::OUTDOOR_LEISURE_TYPES[$normType]))
            || ($normCategory === 'natural' && isset(self::OUTDOOR_NATURAL_TYPES[$normType]))
            || ($normCategory === 'highway' && isset(self::OUTDOOR_HIGHWAY_TYPES[$normType]))
            || ($normCategory === 'tourism' && isset(self::OUTDOOR_TOURISM_TYPES[$normType]))
            || $normCategory === 'waterway';

        if ($isOutdoorFeature && $topLevelName !== null) {
            return $topLevelName;
        }

        return $this->firstCleanPlaceLabel([
            $address['leisure'] ?? null,
            $address['park'] ?? null,
            $address['natural'] ?? null,
            $address['tourism'] ?? null,
            $address['waterway'] ?? null,
            $address['recreation_ground'] ?? null,
        ], $houseNumber);
    }

    /**
     * @param  list<mixed>  $candidates
     */
    private function firstCleanPlaceLabel(array $candidates, ?string $houseNumber): ?string
    {
        foreach ($candidates as $candidate) {
            $cleaned = $this->cleanPlaceLabel($candidate, $houseNumber);

            if ($cleaned !== null) {
                return $cleaned;
            }
        }

        return null;
    }

    private function cleanPlaceLabel(mixed $value, ?string $houseNumber): ?string
    {
        $text = $this->cleanText($value, 160);

        if ($text === null) {
            return null;
        }

        if (isset(self::UNNAMED_VALUES[mb_strtolower($text)])) {
            return null;
        }

        // Never allow a street house number to leak as a place label.
        if ($houseNumber !== null && mb_strtolower($text) === mb_strtolower($houseNumber)) {
            return null;
        }

        if (preg_match('/^\d+[a-zA-Z]?$/', $text) === 1) {
            return null;
        }

        return $text;
    }

    private function cleanText(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, $maxLength);
    }
}
