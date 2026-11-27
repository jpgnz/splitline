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
