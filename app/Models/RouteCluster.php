<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RouteClusterFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $name_override
 * @property string|null $place_label
 * @property string $sport_category
 * @property int|null $representative_activity_id
 * @property bool $is_loop
 * @property float $distance_meters
 * @property float|null $elevation_gain
 * @property float $start_lat
 * @property float $start_lng
 * @property float $end_lat
 * @property float $end_lng
 * @property float $bbox_min_lat
 * @property float $bbox_max_lat
 * @property float $bbox_min_lng
 * @property float $bbox_max_lng
 * @property list<array{0: float, 1: float}> $waypoints
 * @property string|null $encoded_polyline
 * @property int $activity_count
 * @property CarbonImmutable|null $last_activity_at
 * @property CarbonImmutable|null $geocoded_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Activity|null $representativeActivity
 * @property-read Collection<int, ActivityRouteFingerprint> $fingerprints
 */
class RouteCluster extends Model
{
    /** @use HasFactory<RouteClusterFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'name_override',
        'place_label',
        'sport_category',
        'representative_activity_id',
        'is_loop',
        'distance_meters',
        'elevation_gain',
        'start_lat',
        'start_lng',
        'end_lat',
        'end_lng',
        'bbox_min_lat',
        'bbox_max_lat',
        'bbox_min_lng',
        'bbox_max_lng',
        'waypoints',
        'encoded_polyline',
        'activity_count',
        'last_activity_at',
        'geocoded_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'representative_activity_id' => 'integer',
            'is_loop' => 'boolean',
            'distance_meters' => 'float',
            'elevation_gain' => 'float',
            'start_lat' => 'float',
            'start_lng' => 'float',
            'end_lat' => 'float',
            'end_lng' => 'float',
            'bbox_min_lat' => 'float',
            'bbox_max_lat' => 'float',
            'bbox_min_lng' => 'float',
            'bbox_max_lng' => 'float',
            'waypoints' => 'array',
            'activity_count' => 'integer',
            'last_activity_at' => 'immutable_datetime',
            'geocoded_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function representativeActivity(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'representative_activity_id');
    }

    /**
     * @return HasMany<ActivityRouteFingerprint, $this>
     */
    public function fingerprints(): HasMany
    {
        return $this->hasMany(ActivityRouteFingerprint::class, 'route_cluster_id');
    }

    public function displayName(): string
    {
        if (is_string($this->name_override) && trim($this->name_override) !== '') {
            return trim($this->name_override);
        }

        return $this->name;
    }

    /**
     * Scope clusters that qualify as matched routes (two or more attempts).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMatched(Builder $query): Builder
    {
        return $query->where('activity_count', '>=', 2);
    }
}
