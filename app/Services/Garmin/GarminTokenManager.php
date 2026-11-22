<?php

namespace App\Services\Garmin;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

class GarminTokenManager
{
    public const string TOKEN_PATH = '/personalapitoken-service/personal-api/personal-api-key/token';

    public const int REFRESH_WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly CacheRepository $cache,
    ) {}

    public function getAccessToken(bool $forceRefresh = false): string
    {
        $apiKey = trim($this->apiKey);

        if ($apiKey === '') {
            throw GarminApiException::missingApiKey();
        }

        $cacheKey = $this->cacheKey($apiKey);

        if (! $forceRefresh) {
            $cached = $this->cache->get($cacheKey);

            if (
                is_array($cached)
                && isset($cached['access_token'], $cached['expires_at'])
                && is_string($cached['access_token'])
                && is_int($cached['expires_at'])
                && ($cached['expires_at'] - Date::now()->getTimestamp()) > self::REFRESH_WINDOW_SECONDS
            ) {
                return $cached['access_token'];
            }
        }

        $baseUrl = rtrim($this->baseUrl, '/');
        $url = $baseUrl.self::TOKEN_PATH;

        $response = Http::baseUrl($baseUrl)
            ->connectTimeout(5)
            ->timeout($this->timeout)
            ->withHeaders([
                'Accept' => '*/*',
                'Authorization' => 'ApiKey '.$apiKey,
            ])
            ->post(self::TOKEN_PATH);

        if (! $response->successful()) {
            throw GarminApiException::httpError('POST', $url, $response->status(), $response->body());
        }

        $accessToken = trim($response->body());
        $expiresAt = $this->extractJwtExpiration($accessToken);
        $ttlSeconds = max(0, $expiresAt - Date::now()->getTimestamp() - self::REFRESH_WINDOW_SECONDS);

        if ($ttlSeconds > 0) {
            $this->cache->put(
                $cacheKey,
                [
                    'access_token' => $accessToken,
                    'expires_at' => $expiresAt,
                ],
                $ttlSeconds,
            );
        }

        return $accessToken;
    }

    public function forgetToken(): void
    {
        $apiKey = trim($this->apiKey);

        if ($apiKey === '') {
            return;
        }

        $this->cache->forget($this->cacheKey($apiKey));
    }

    private function cacheKey(string $apiKey): string
    {
        $fingerprint = hash('sha256', rtrim($this->baseUrl, '/')."\0".$apiKey);

        return "garmin.personal_api.bearer_token.{$fingerprint}";
    }

    private function extractJwtExpiration(string $accessToken): int
    {
        $segments = explode('.', $accessToken);

        if (count($segments) < 2) {
            throw GarminApiException::invalidTokenResponse('expected a JWT payload.');
        }

        $encodedPayload = strtr($segments[1], '-_', '+/');
        $padding = strlen($encodedPayload) % 4;

        if ($padding !== 0) {
            $encodedPayload .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($encodedPayload, true);

        if ($decoded === false) {
            throw GarminApiException::invalidTokenResponse('unable to base64-decode JWT payload.');
        }

        $payload = json_decode($decoded, true);

        if (! is_array($payload) || ! isset($payload['exp']) || ! is_numeric($payload['exp'])) {
            throw GarminApiException::invalidTokenResponse('JWT payload is missing a numeric exp claim.');
        }

        return (int) $payload['exp'];
    }
}
