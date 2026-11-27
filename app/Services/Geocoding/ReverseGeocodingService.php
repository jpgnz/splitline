<?php

namespace App\Services\Geocoding;

use App\Models\Activity;
use App\Models\ActivityRouteFingerprint;
use App\Models\GeocodedPlace;
use App\Models\RouteCluster;
use App\Services\Geocoding\Exceptions\GeocodingUnavailableException;
use App\Services\Routes\RouteMatchingService;
use Carbon\CarbonImmutable;

/**
 * Resolves privacy-friendly outdoor place names for Matched Routes and activities missing location_name.
 */
class ReverseGeocodingService
{
    public function __construct(
        private readonly NominatimClient $client,
    ) {}

    /**
     * Resolve an exact road, trail, park, peak, or landmark using the high-precision feature tier (zoom 17, ~25m).
     *
     * @throws GeocodingUnavailableException
     */
    public function resolveFeature(float $lat, float $lng, bool $force = false): ?GeocodedPlace
    {
        return $this->client->reverse($lat, $lng, GeocodedPlace::TIER_FEATURE, $force);
    }

    /**
     * Resolve a suburb, neighbourhood, town, or city using the area tier (zoom 14, ~220m).
     *
     * @throws GeocodingUnavailableException
     */
    public function resolveArea(float $lat, float $lng, bool $force = false): ?GeocodedPlace
    {
        return $this->client->reverse($lat, $lng, GeocodedPlace::TIER_AREA, $force);
    }

    /**
     * Format a clean location subtitle (e.g. "Cornwall Park · Auckland") without repeating identical parts.
     */
    public function formatSubtitle(?GeocodedPlace $place): ?string
    {
        return $place?->subtitle();
    }

    /**
     * Enrich a matched RouteCluster with an OSM place_label and upgrade generic fallback route names.
     *
     * Every completed lookup, including one that finds no usable place, records `geocode_attempted_at`
     * so unresolvable routes are only retried after the configured retry window.
     *
     * @throws GeocodingUnavailableException
     */
    public function enrichRouteCluster(RouteCluster $cluster, bool $force = false): bool
    {
        if (! $force && $cluster->activity_count < 2) {
            return false;
        }

        if (! $force && $cluster->geocoded_at !== null) {
            return false;
        }

        $labels = $this->resolveClusterLabels($cluster, $force);
        $now = CarbonImmutable::now('UTC');

        if ($labels === null) {
            $cluster->update(['geocode_attempted_at' => $now]);

            return false;
        }

        $updates = [
            'place_label' => mb_substr($labels['place_label'], 0, 160),
            'geocoded_at' => $now,
            'geocode_attempted_at' => $now,
        ];

        $shortPlace = $labels['short_place'];
        $linkedActivities = $this->loadClusterActivities($cluster);

        if (
            $shortPlace !== null
            && RouteMatchingService::shouldUpgradeClusterNameWithPlace($cluster, $linkedActivities)
        ) {
            $updates['name'] = RouteMatchingService::formatGeocodedClusterName(
                $shortPlace,
                $cluster->is_loop,
                $cluster->distance_meters,
            );
        }

        $cluster->update($updates);

        return true;
    }

    /**
     * Populate an outdoor activity's missing location_name from the area precision tier.
     *
     * Provider-supplied location names are never overwritten, even when forced; forcing only refreshes
     * names that were previously filled by reverse geocoding (`location_geocoded_at`).
     *
     * @throws GeocodingUnavailableException
     */
    public function enrichActivityLocation(Activity $activity, bool $force = false): bool
    {
        if ($activity->is_indoor || $activity->start_lat === null || $activity->start_lng === null) {
            return false;
        }

        $hasLocation = is_string($activity->location_name) && trim($activity->location_name) !== '';

        if ($hasLocation && (! $force || $activity->location_geocoded_at === null)) {
            return false;
        }

        $place = $this->resolveArea($activity->start_lat, $activity->start_lng, $force);
        $location = $place !== null ? $this->formatActivityLocation($place) : null;
        $now = CarbonImmutable::now('UTC');

        if ($location === null) {
            $activity->update(['geocode_attempted_at' => $now]);

            return false;
        }

        $activity->update([
            'location_name' => mb_substr($location, 0, 160),
            'location_geocoded_at' => $now,
            'geocode_attempted_at' => $now,
        ]);

        return true;
    }

    /**
     * Count matched route clusters and outdoor activities still awaiting geocoding.
     */
    public function pendingCount(): int
    {
        return RouteCluster::query()->awaitingGeocoding()->count()
            + Activity::query()->awaitingGeocoding()->count();
    }

    /**
     * Resolve a cluster's subtitle and short naming place, or null when no usable place was found.
     *
     * @return array{place_label: string, short_place: ?string}|null
     *
     * @throws GeocodingUnavailableException
     */
    private function resolveClusterLabels(RouteCluster $cluster, bool $force): ?array
    {
        $placeLabel = null;
        $shortPlace = null;

        if ($cluster->is_loop) {
            [$targetLat, $targetLng] = $this->furthestWaypointFromStart($cluster);
            $feature = $this->resolveFeature($targetLat, $targetLng, $force);

            if ($feature !== null) {
                $placeLabel = $this->formatSubtitle($feature);
                $shortPlace = $feature->display_label ?? $feature->area_label ?? $feature->city;
            }
        } else {
            $startArea = $this->resolveArea($cluster->start_lat, $cluster->start_lng, $force);
            $endArea = $this->resolveArea($cluster->end_lat, $cluster->end_lng, $force);

            $startLabel = $startArea !== null
                ? ($startArea->area_label ?? $startArea->display_label ?? $startArea->city)
                : null;
            $endLabel = $endArea !== null
                ? ($endArea->area_label ?? $endArea->display_label ?? $endArea->city)
                : null;

            if (
                $startLabel !== null
                && $endLabel !== null
                && mb_strtolower($startLabel) !== mb_strtolower($endLabel)
            ) {
                $shortPlace = "{$startLabel} → {$endLabel}";
                $placeLabel = $shortPlace;
            } else {
                [$midLat, $midLng] = $this->midpointWaypoint($cluster);
                $midFeature = $this->resolveFeature($midLat, $midLng, $force);

                if ($midFeature !== null) {
                    $placeLabel = $this->formatSubtitle($midFeature);
                    $shortPlace = $midFeature->display_label ?? $midFeature->area_label ?? $midFeature->city;
                } elseif ($startArea !== null) {
                    $placeLabel = $this->formatSubtitle($startArea);
                    $shortPlace = $startLabel;
                }
            }
        }

        if ($placeLabel === null || trim($placeLabel) === '') {
            return null;
        }

        return [
            'place_label' => trim($placeLabel),
            'short_place' => $shortPlace !== null && trim($shortPlace) !== '' ? trim($shortPlace) : null,
        ];
    }

    /**
     * Pick the waypoint furthest from the route start so loops are named after their destination,
     * not the user's home street.
     *
     * @return array{0: float, 1: float}
     */
    private function furthestWaypointFromStart(RouteCluster $cluster): array
    {
        $waypoints = $cluster->waypoints;

        if ($waypoints === []) {
            return [
                ($cluster->bbox_min_lat + $cluster->bbox_max_lat) / 2.0,
                ($cluster->bbox_min_lng + $cluster->bbox_max_lng) / 2.0,
            ];
        }

        $startLat = $cluster->start_lat;
        $startLng = $cluster->start_lng;
        $furthest = $waypoints[0];
        $maxDistance = -1.0;

        foreach ($waypoints as $point) {
            $dist = RouteMatchingService::haversineMeters($startLat, $startLng, $point[0], $point[1]);

            if ($dist > $maxDistance) {
                $maxDistance = $dist;
                $furthest = $point;
            }
        }

        return [$furthest[0], $furthest[1]];
    }

    /**
     * Pick the midpoint waypoint along a route cluster.
     *
     * @return array{0: float, 1: float}
     */
    private function midpointWaypoint(RouteCluster $cluster): array
    {
        $waypoints = $cluster->waypoints;

        if ($waypoints !== []) {
            $mid = $waypoints[intdiv(count($waypoints), 2)];

            return [$mid[0], $mid[1]];
        }

        return [
            ($cluster->start_lat + $cluster->end_lat) / 2.0,
            ($cluster->start_lng + $cluster->end_lng) / 2.0,
        ];
    }

    private function formatActivityLocation(GeocodedPlace $place): ?string
    {
        $area = is_string($place->area_label) && trim($place->area_label) !== '' ? trim($place->area_label) : null;
        $city = is_string($place->city) && trim($place->city) !== '' ? trim($place->city) : null;
        $region = is_string($place->region) && trim($place->region) !== '' ? trim($place->region) : null;

        if ($area !== null && $city !== null && mb_strtolower($area) !== mb_strtolower($city)) {
            return "{$area}, {$city}";
        }

        return $city ?? $area ?? $region ?? $place->display_label;
    }

    /**
     * @return list<Activity>
     */
    private function loadClusterActivities(RouteCluster $cluster): array
    {
        $fingerprints = ActivityRouteFingerprint::query()
            ->with('activity')
            ->where('route_cluster_id', $cluster->id)
            ->where('is_unlinked', false)
            ->get();

        /** @var list<Activity> $activities */
        $activities = [];

        foreach ($fingerprints as $fp) {
            $activity = $fp->getRelation('activity');

            if ($activity instanceof Activity) {
                $activities[] = $activity;
            }
        }

        return $activities;
    }
}
