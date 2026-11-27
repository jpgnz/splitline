<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SegmentEffortFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $segment_id
 * @property int $activity_id
 * @property CarbonImmutable $started_at_local
 * @property int $start_index
 * @property int $end_index
 * @property float $start_distance_meters
 * @property float $end_distance_meters
 * @property float $distance_meters
 * @property float $elapsed_seconds
 * @property float|null $moving_seconds
 * @property float|null $avg_speed
 * @property float|null $elevation_gain
 * @property float|null $avg_grade_pct
 * @property float|null $vam
 * @property int|null $avg_hr
 * @property int|null $max_hr
 * @property float|null $avg_power
 * @property float|null $w_per_kg
 * @property float|null $avg_cadence
 * @property int|null $rank
 * @property bool $is_pr
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Segment $segment
 * @property-read Activity $activity
 */
class SegmentEffort extends Model
{
    /** @use HasFactory<SegmentEffortFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'segment_id',
        'activity_id',
        'started_at_local',
        'start_index',
        'end_index',
        'start_distance_meters',
        'end_distance_meters',
        'distance_meters',
        'elapsed_seconds',
        'moving_seconds',
        'avg_speed',
        'elevation_gain',
        'avg_grade_pct',
        'vam',
        'avg_hr',
        'max_hr',
        'avg_power',
        'w_per_kg',
        'avg_cadence',
        'rank',
        'is_pr',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'segment_id' => 'integer',
            'activity_id' => 'integer',
            'started_at_local' => 'immutable_datetime',
            'start_index' => 'integer',
            'end_index' => 'integer',
            'start_distance_meters' => 'float',
            'end_distance_meters' => 'float',
            'distance_meters' => 'float',
            'elapsed_seconds' => 'float',
            'moving_seconds' => 'float',
            'avg_speed' => 'float',
            'elevation_gain' => 'float',
            'avg_grade_pct' => 'float',
            'vam' => 'float',
            'avg_hr' => 'integer',
            'max_hr' => 'integer',
            'avg_power' => 'float',
            'w_per_kg' => 'float',
            'avg_cadence' => 'float',
            'rank' => 'integer',
            'is_pr' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Segment, $this>
     */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class, 'segment_id');
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'activity_id');
    }
}
