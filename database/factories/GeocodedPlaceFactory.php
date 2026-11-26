<?php

namespace Database\Factories;

use App\Models\GeocodedPlace;
use App\Services\Geocoding\NominatimClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeocodedPlace>
 */
class GeocodedPlaceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $lat = round(fake()->randomFloat(5, -36.92, -36.82), 5);
        $lng = round(fake()->randomFloat(5, 174.70, 174.85), 5);
        $tier = GeocodedPlace::TIER_FEATURE;
        $bucket = NominatimClient::bucketCoordinates($lat, $lng, $tier);

        return [
            'precision_tier' => $tier,
            'zoom' => $bucket['zoom'],
            'lat_bucket' => $bucket['lat_bucket'],
            'lng_bucket' => $bucket['lng_bucket'],
            'lat' => $lat,
            'lng' => $lng,
            'provider_host' => NominatimClient::PUBLIC_HOST,
            'osm_type' => 'way',
            'osm_id' => fake()->numberBetween(100000, 99999999),
            'category' => 'leisure',
            'type' => 'park',
            'feature_name' => 'Cornwall Park',
            'road' => 'Green Lane West',
            'neighbourhood' => 'One Tree Hill',
            'suburb' => 'Greenlane',
            'city' => 'Auckland',
            'region' => 'Auckland',
            'country' => 'New Zealand',
            'country_code' => 'nz',
            'display_label' => 'Cornwall Park',
            'area_label' => 'Greenlane',
            'raw_address' => [
                'leisure' => 'Cornwall Park',
                'road' => 'Green Lane West',
                'suburb' => 'Greenlane',
                'city' => 'Auckland',
                'country' => 'New Zealand',
                'country_code' => 'nz',
            ],
            'fetched_at' => CarbonImmutable::now('UTC'),
        ];
    }

    public function area(): static
    {
        return $this->state(function (array $attributes): array {
            $lat = is_numeric($attributes['lat'] ?? null) ? (float) $attributes['lat'] : -36.85;
            $lng = is_numeric($attributes['lng'] ?? null) ? (float) $attributes['lng'] : 174.76;
            $bucket = NominatimClient::bucketCoordinates($lat, $lng, GeocodedPlace::TIER_AREA);

            return [
                'precision_tier' => GeocodedPlace::TIER_AREA,
                'zoom' => $bucket['zoom'],
                'lat_bucket' => $bucket['lat_bucket'],
                'lng_bucket' => $bucket['lng_bucket'],
                'category' => 'place',
                'type' => 'suburb',
                'feature_name' => null,
                'display_label' => 'Ponsonby',
                'area_label' => 'Ponsonby',
                'suburb' => 'Ponsonby',
                'city' => 'Auckland',
            ];
        });
    }
}
