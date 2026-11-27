<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ActivityRouteFingerprintFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $activity_id
 * @property int|null $route_cluster_id
 * @property float $start_lat
 * @property float $start_lng
 * @property float $end_lat
 * @property float $end_lng
 * @property float $bbox_min_lat
 * @property float $bbox_max_lat
 * @property float $bbox_min_lng
 * @property float $bbox_max_lng
 * @property float $distance_meters
 * @property float $start_to_end_meters
 * @property bool $is_loop
 * @property list<array{0: float, 1: float}> $waypoints
 * @property string|null $encoded_preview_polyline
 * @property bool $is_unlinked
 * @property string|null $preserved_name_override
 * @property CarbonImmutable $computed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Activity $activity
 * @property-read RouteCluster|null $cluster
 */
class ActivityRouteFingerprint extends Model
{
    /** @use HasFactory<ActivityRouteFingerprintFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'activity_id',
        'route_cluster_id',
        'start_lat',
        'start_lng',
        'end_lat',
        'end_lng',
        'bbox_min_lat',
        'bbox_max_lat',
        'bbox_min_lng',
        'bbox_max_lng',
        'distance_meters',
        'start_to_end_meters',
        'is_loop',
        'waypoints',
        'encoded_preview_polyline',
        'is_unlinked',
        'preserved_name_override',
        'computed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activity_id' => 'integer',
            'route_cluster_id' => 'integer',
            'start_lat' => 'float',
            'start_lng' => 'float',
            'end_lat' => 'float',
            'end_lng' => 'float',
            'bbox_min_lat' => 'float',
            'bbox_max_lat' => 'float',
            'bbox_min_lng' => 'float',
            'bbox_max_lng' => 'float',
            'distance_meters' => 'float',
            'start_to_end_meters' => 'float',
            'is_loop' => 'boolean',
            'waypoints' => 'array',
            'is_unlinked' => 'boolean',
            'computed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /**
     * @return BelongsTo<RouteCluster, $this>
     */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(RouteCluster::class, 'route_cluster_id');
    }
}
