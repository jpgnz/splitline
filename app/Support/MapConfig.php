<?php

namespace App\Support;

class MapConfig
{
    public const string PUBLIC_TILES_HOST = 'tiles.openfreemap.org';

    public const string PUBLIC_TERRAIN_HOST = 'tiles.mapterhorn.com';

    public const string DEFAULT_STYLE_LIGHT_URL = 'https://tiles.openfreemap.org/styles/positron';

    public const string DEFAULT_STYLE_DARK_URL = 'https://tiles.openfreemap.org/styles/dark';

    public const string DEFAULT_TERRAIN_TILEJSON_URL = 'https://tiles.mapterhorn.com/tilejson.json';

    public function isEnabled(): bool
    {
        return $this->parseOptionalBool(config('services.maps.enabled')) ?? true;
    }

    public function tilesEnabled(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $override = $this->parseOptionalBool(config('services.maps.tiles_enabled'));

        return $override ?? true;
    }

    public function terrainEnabled(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $override = $this->parseOptionalBool(config('services.maps.terrain_enabled'));

        return $override ?? true;
    }

    public function styleLightUrl(): ?string
    {
        if (! $this->tilesEnabled()) {
            return null;
        }

        return $this->resolveHttpUrl(config('services.maps.style_light_url'), self::DEFAULT_STYLE_LIGHT_URL);
    }

    public function styleDarkUrl(): ?string
    {
        if (! $this->tilesEnabled()) {
            return null;
        }

        return $this->resolveHttpUrl(config('services.maps.style_dark_url'), self::DEFAULT_STYLE_DARK_URL);
    }

    public function terrainTilejsonUrl(): ?string
    {
        if (! $this->terrainEnabled()) {
            return null;
        }

        return $this->resolveHttpUrl(config('services.maps.terrain_tilejson_url'), self::DEFAULT_TERRAIN_TILEJSON_URL);
    }

    public function tilesHost(): string
    {
        $lightHost = $this->extractHost(
            $this->resolveHttpUrl(config('services.maps.style_light_url'), self::DEFAULT_STYLE_LIGHT_URL),
            self::PUBLIC_TILES_HOST,
        );
        $darkHost = $this->extractHost(
            $this->resolveHttpUrl(config('services.maps.style_dark_url'), self::DEFAULT_STYLE_DARK_URL),
            self::PUBLIC_TILES_HOST,
        );

        if ($lightHost === $darkHost) {
            return $lightHost;
        }

        return "{$lightHost}, {$darkHost}";
    }

    public function terrainHost(): string
    {
        return $this->extractHost(
            $this->resolveHttpUrl(config('services.maps.terrain_tilejson_url'), self::DEFAULT_TERRAIN_TILEJSON_URL),
            self::PUBLIC_TERRAIN_HOST,
        );
    }

    public function isPublicTilesHost(): bool
    {
        $lightHost = $this->extractHost(
            $this->resolveHttpUrl(config('services.maps.style_light_url'), self::DEFAULT_STYLE_LIGHT_URL),
            self::PUBLIC_TILES_HOST,
        );
        $darkHost = $this->extractHost(
            $this->resolveHttpUrl(config('services.maps.style_dark_url'), self::DEFAULT_STYLE_DARK_URL),
            self::PUBLIC_TILES_HOST,
        );

        return $lightHost === self::PUBLIC_TILES_HOST && $darkHost === self::PUBLIC_TILES_HOST;
    }

    public function isPublicTerrainHost(): bool
    {
        return $this->terrainHost() === self::PUBLIC_TERRAIN_HOST;
    }

    /**
     * @return array{
     *     tiles_enabled: bool,
     *     terrain_enabled: bool,
     *     style_light_url: ?string,
     *     style_dark_url: ?string,
     *     terrain_tilejson_url: ?string
     * }
     */
    public function sharedFrontendPayload(): array
    {
        return [
            'tiles_enabled' => $this->tilesEnabled(),
            'terrain_enabled' => $this->terrainEnabled(),
            'style_light_url' => $this->styleLightUrl(),
            'style_dark_url' => $this->styleDarkUrl(),
            'terrain_tilejson_url' => $this->terrainTilejsonUrl(),
        ];
    }

    /**
     * @return array{
     *     enabled: bool,
     *     tiles_enabled: bool,
     *     terrain_enabled: bool,
     *     tiles_host: string,
     *     terrain_host: string,
     *     is_public_tiles_host: bool,
     *     is_public_terrain_host: bool
     * }
     */
    public function settingsPayload(): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'tiles_enabled' => $this->tilesEnabled(),
            'terrain_enabled' => $this->terrainEnabled(),
            'tiles_host' => $this->tilesHost(),
            'terrain_host' => $this->terrainHost(),
            'is_public_tiles_host' => $this->isPublicTilesHost(),
            'is_public_terrain_host' => $this->isPublicTerrainHost(),
        ];
    }

    private function parseOptionalBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return null;
            }

            return filter_var($trimmed, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        return null;
    }

    private function resolveHttpUrl(mixed $value, string $default): string
    {
        if (! is_string($value)) {
            return $default;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return $default;
        }

        $scheme = parse_url($trimmed, PHP_URL_SCHEME);
        $host = parse_url($trimmed, PHP_URL_HOST);

        if (! is_string($scheme) || ! is_string($host) || $host === '') {
            return $default;
        }

        if (! in_array(strtolower($scheme), ['http', 'https'], true)) {
            return $default;
        }

        return $trimmed;
    }

    private function extractHost(string $url, string $fallback): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && trim($host) !== ''
            ? strtolower(trim($host))
            : $fallback;
    }
}
