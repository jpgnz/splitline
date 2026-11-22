<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GoalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $title
 * @property string $sport
 * @property string $metric
 * @property string $period
 * @property float $target_value
 * @property int|null $year
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Goal extends Model
{
    /** @use HasFactory<GoalFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    public const array VALID_SPORTS = [
        'all',
        'running',
        'road_running',
        'trail_running',
        'track_running',
        'treadmill_running',
        'cycling',
        'road_cycling',
        'gravel_cycling',
        'mtb_cycling',
        'indoor_cycling',
        'ebike_cycling',
        'commute_cycling',
        'hiking',
        'walking',
        'swimming',
        'cardio',
        'biking',
        'road_biking',
        'mtb_biking',
    ];

    /**
     * @var list<string>
     */
    public const array VALID_METRICS = [
        'distance_km',
        'elevation_m',
        'duration_hours',
        'activity_count',
    ];

    /**
     * @var list<string>
     */
    public const array VALID_PERIODS = [
        'weekly',
        'monthly',
        'yearly',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'sport',
        'metric',
        'period',
        'target_value',
        'year',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_value' => 'float',
            'year' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }
}
