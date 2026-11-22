<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BestEffortFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $activity_id
 * @property string $sport
 * @property string $distance_key
 * @property float $distance_meters
 * @property float $elapsed_seconds
 * @property float|null $moving_seconds
 * @property float|null $start_offset_seconds
 * @property int|null $avg_hr
 * @property float|null $elevation_delta
 * @property float|null $start_distance_meters
 * @property float|null $end_distance_meters
 * @property CarbonImmutable $achieved_at
 * @property string $source
 * @property float|null $override_seconds
 * @property bool $is_excluded
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Activity $activity
 */
class BestEffort extends Model
{
    /** @use HasFactory<BestEffortFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'activity_id',
        'sport',
        'distance_key',
        'distance_meters',
        'elapsed_seconds',
        'moving_seconds',
        'start_offset_seconds',
        'avg_hr',
        'elevation_delta',
        'start_distance_meters',
        'end_distance_meters',
        'achieved_at',
        'source',
        'override_seconds',
        'is_excluded',
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
            'distance_meters' => 'float',
            'elapsed_seconds' => 'float',
            'moving_seconds' => 'float',
            'start_offset_seconds' => 'float',
            'avg_hr' => 'integer',
            'elevation_delta' => 'float',
            'start_distance_meters' => 'float',
            'end_distance_meters' => 'float',
            'achieved_at' => 'immutable_datetime',
            'override_seconds' => 'float',
            'is_excluded' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function effectiveSeconds(): float
    {
        return $this->override_seconds ?? $this->elapsed_seconds;
    }
}
