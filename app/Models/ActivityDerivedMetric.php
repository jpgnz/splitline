<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ActivityDerivedMetricFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $activity_id
 * @property float|null $decoupling_pct
 * @property string|null $decoupling_metric
 * @property string|null $decoupling_rating
 * @property float|null $efficiency_factor
 * @property float|null $first_half_ef
 * @property float|null $second_half_ef
 * @property string|null $pacing_strategy
 * @property float|null $split_ratio
 * @property float|null $pace_cv_pct
 * @property float|null $hr_low_pct
 * @property float|null $hr_moderate_pct
 * @property float|null $hr_high_pct
 * @property float|null $end_lat
 * @property float|null $end_lng
 * @property float|null $start_to_end_meters
 * @property bool|null $is_loop
 * @property float|null $bbox_min_lat
 * @property float|null $bbox_max_lat
 * @property float|null $bbox_min_lng
 * @property float|null $bbox_max_lng
 * @property list<array{0: float, 1: float}>|null $route_fingerprint
 * @property CarbonImmutable $computed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Activity $activity
 */
class ActivityDerivedMetric extends Model
{
    /** @use HasFactory<ActivityDerivedMetricFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'activity_id',
        'decoupling_pct',
        'decoupling_metric',
        'decoupling_rating',
        'efficiency_factor',
        'first_half_ef',
        'second_half_ef',
        'pacing_strategy',
        'split_ratio',
        'pace_cv_pct',
        'hr_low_pct',
        'hr_moderate_pct',
        'hr_high_pct',
        'end_lat',
        'end_lng',
        'start_to_end_meters',
        'is_loop',
        'bbox_min_lat',
        'bbox_max_lat',
        'bbox_min_lng',
        'bbox_max_lng',
        'route_fingerprint',
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
            'decoupling_pct' => 'float',
            'efficiency_factor' => 'float',
            'first_half_ef' => 'float',
            'second_half_ef' => 'float',
            'split_ratio' => 'float',
            'pace_cv_pct' => 'float',
            'hr_low_pct' => 'float',
            'hr_moderate_pct' => 'float',
            'hr_high_pct' => 'float',
            'end_lat' => 'float',
            'end_lng' => 'float',
            'start_to_end_meters' => 'float',
            'is_loop' => 'boolean',
            'bbox_min_lat' => 'float',
            'bbox_max_lat' => 'float',
            'bbox_min_lng' => 'float',
            'bbox_max_lng' => 'float',
            'route_fingerprint' => 'array',
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
}
