<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SegmentFactory;
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
 * @property string $kind
 * @property string $sport_category
 * @property int|null $source_activity_id
 * @property string|null $climb_category
 * @property float $distance_meters
 * @property float|null $elevation_gain
 * @property float|null $elevation_loss
 * @property float|null $avg_grade_pct
 * @property float|null $max_grade_pct
 * @property float|null $fiets_score
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
 * @property bool $is_starred
 * @property int $effort_count
 * @property float|null $best_elapsed_seconds
 * @property int|null $best_effort_id
 * @property CarbonImmutable|null $last_effort_at
 * @property CarbonImmutable|null $geocoded_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Activity|null $sourceActivity
 * @property-read SegmentEffort|null $bestEffort
 * @property-read Collection<int, SegmentEffort> $efforts
 */
class Segment extends Model
{
    /** @use HasFactory<SegmentFactory> */
    use HasFactory;

    public const string KIND_CLIMB = 'climb';

    public const string KIND_CUSTOM = 'custom';

    /**
     * Ordered climb categories from hardest to easiest.
     *
     * @var list<string>
     */
    public const array CLIMB_CATEGORIES = [
        'hc',
        'cat1',
        'cat2',
        'cat3',
        'cat4',
        'uncategorized',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'name_override',
        'place_label',
        'kind',
        'sport_category',
        'source_activity_id',
        'climb_category',
        'distance_meters',
        'elevation_gain',
        'elevation_loss',
        'avg_grade_pct',
        'max_grade_pct',
        'fiets_score',
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
        'is_starred',
        'effort_count',
        'best_elapsed_seconds',
        'best_effort_id',
        'last_effort_at',
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
            'source_activity_id' => 'integer',
            'distance_meters' => 'float',
            'elevation_gain' => 'float',
            'elevation_loss' => 'float',
            'avg_grade_pct' => 'float',
            'max_grade_pct' => 'float',
            'fiets_score' => 'float',
            'start_lat' => 'float',
            'start_lng' => 'float',
            'end_lat' => 'float',
            'end_lng' => 'float',
            'bbox_min_lat' => 'float',
            'bbox_max_lat' => 'float',
            'bbox_min_lng' => 'float',
            'bbox_max_lng' => 'float',
            'waypoints' => 'array',
            'is_starred' => 'boolean',
            'effort_count' => 'integer',
            'best_elapsed_seconds' => 'float',
            'best_effort_id' => 'integer',
            'last_effort_at' => 'immutable_datetime',
            'geocoded_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function sourceActivity(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'source_activity_id');
    }

    /**
     * @return HasMany<SegmentEffort, $this>
     */
    public function efforts(): HasMany
    {
        return $this->hasMany(SegmentEffort::class, 'segment_id');
    }

    /**
     * @return BelongsTo<SegmentEffort, $this>
     */
    public function bestEffort(): BelongsTo
    {
        return $this->belongsTo(SegmentEffort::class, 'best_effort_id');
    }

    public function displayName(): string
    {
        if (is_string($this->name_override) && trim($this->name_override) !== '') {
            return trim($this->name_override);
        }

        return $this->name;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeClimbs(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_CLIMB);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_CUSTOM);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeStarred(Builder $query): Builder
    {
        return $query->where('is_starred', true);
    }
}
