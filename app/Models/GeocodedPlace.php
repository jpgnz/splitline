<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GeocodedPlaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $precision_tier
 * @property int $zoom
 * @property int $lat_bucket
 * @property int $lng_bucket
 * @property float $lat
 * @property float $lng
 * @property string $provider_host
 * @property string|null $osm_type
 * @property int|null $osm_id
 * @property string|null $category
 * @property string|null $type
 * @property string|null $feature_name
 * @property string|null $road
 * @property string|null $neighbourhood
 * @property string|null $suburb
 * @property string|null $city
 * @property string|null $region
 * @property string|null $country
 * @property string|null $country_code
 * @property string|null $display_label
 * @property string|null $area_label
 * @property array<string, mixed>|null $raw_address
 * @property CarbonImmutable $fetched_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class GeocodedPlace extends Model
{
    /** @use HasFactory<GeocodedPlaceFactory> */
    use HasFactory;

    public const string TIER_AREA = 'area';

    public const string TIER_FEATURE = 'feature';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'precision_tier',
        'zoom',
        'lat_bucket',
        'lng_bucket',
        'lat',
        'lng',
        'provider_host',
        'osm_type',
        'osm_id',
        'category',
        'type',
        'feature_name',
        'road',
        'neighbourhood',
        'suburb',
        'city',
        'region',
        'country',
        'country_code',
        'display_label',
        'area_label',
        'raw_address',
        'fetched_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'zoom' => 'integer',
            'lat_bucket' => 'integer',
            'lng_bucket' => 'integer',
            'lat' => 'float',
            'lng' => 'float',
            'osm_id' => 'integer',
            'raw_address' => 'array',
            'fetched_at' => 'immutable_datetime',
        ];
    }

    /**
     * Build a concise location subtitle (e.g. "Cornwall Park · Auckland") without repeating identical parts.
     */
    public function subtitle(): ?string
    {
        $primary = is_string($this->display_label) && trim($this->display_label) !== ''
            ? trim($this->display_label)
            : (is_string($this->area_label) && trim($this->area_label) !== '' ? trim($this->area_label) : null);

        $contextCandidates = [$this->city, $this->area_label, $this->region];
        $context = null;

        foreach ($contextCandidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $trimmed = trim($candidate);

            if ($primary === null) {
                return mb_substr($trimmed, 0, 160);
            }

            if (
                mb_strtolower($trimmed) !== mb_strtolower($primary)
                && ! str_contains(mb_strtolower($primary), mb_strtolower($trimmed))
            ) {
                $context = $trimmed;
                break;
            }
        }

        if ($primary === null) {
            return null;
        }

        if ($context !== null) {
            return mb_substr("{$primary} · {$context}", 0, 160);
        }

        return mb_substr($primary, 0, 160);
    }
}
