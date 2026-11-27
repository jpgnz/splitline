<?php

namespace App\Models;

use App\Support\AppSettings;
use Carbon\CarbonImmutable;
use Database\Factories\SegmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
 * @property int $detection_count
 * @property bool $is_sub_climb
 * @property bool $is_climb_verified
 * @property float|null $best_elapsed_seconds
 * @property int|null $best_effort_id
 * @property CarbonImmutable|null $last_effort_at
 * @property CarbonImmutable|null $geocoded_at
 * @property CarbonImmutable|null $geocode_attempted_at
 * @property CarbonImmutable|null $deleted_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Activity|null $sourceActivity
 * @property-read SegmentEffort|null $bestEffort
 * @property-read Collection<int, SegmentEffort> $efforts
 * @property-read Collection<int, Activity> $detectedActivities
 */
class Segment extends Model
{
    /** @use HasFactory<SegmentFactory> */
    use HasFactory;

    use SoftDeletes;

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
        'detection_count',
        'is_sub_climb',
        'is_climb_verified',
        'best_elapsed_seconds',
        'best_effort_id',
        'last_effort_at',
        'geocoded_at',
        'geocode_attempted_at',
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
            'detection_count' => 'integer',
            'is_sub_climb' => 'boolean',
            'is_climb_verified' => 'boolean',
            'best_elapsed_seconds' => 'float',
            'best_effort_id' => 'integer',
            'last_effort_at' => 'immutable_datetime',
            'geocoded_at' => 'immutable_datetime',
            'geocode_attempted_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
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

    /**
     * Activities in which the climb detector independently found this climb (one row per activity).
     *
     * @return BelongsToMany<Activity, $this>
     */
    public function detectedActivities(): BelongsToMany
    {
        return $this->belongsToMany(Activity::class, 'segment_detections')->withTimestamps();
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
     * Scope segments that should be surfaced in the directory, activity panels, and geocoding queue.
     * Custom segments and starred segments are always visible; auto-detected climbs require verified
     * climbing telemetry and at least `$minEfforts` passes (and independent detections if nested inside
     * a longer climb).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisible(Builder $query, ?int $minEfforts = null): Builder
    {
        $threshold = max(
            AppSettings::MIN_SEGMENTS_MIN_EFFORTS,
            $minEfforts ?? app(AppSettings::class)->segmentsMinEfforts(),
        );

        return $query->where(function (Builder $sub) use ($threshold): void {
            $sub->where('kind', self::KIND_CUSTOM)
                ->orWhere('is_starred', true)
                ->orWhere(function (Builder $climb) use ($threshold): void {
                    $climb
                        ->where('kind', self::KIND_CLIMB)
                        ->where('is_climb_verified', true)
                        ->where('effort_count', '>=', $threshold)
                        ->where(function (Builder $subClimbCheck) use ($threshold): void {
                            $subClimbCheck
                                ->where('is_sub_climb', false)
                                ->orWhere('detection_count', '>=', $threshold);
                        });
                });
        });
    }

    /**
     * Determine whether this segment satisfies the current visibility threshold.
     */
    public function isVisible(?int $minEfforts = null): bool
    {
        if ($this->kind === self::KIND_CUSTOM || $this->is_starred) {
            return true;
        }

        if (! $this->is_climb_verified) {
            return false;
        }

        $threshold = max(
            AppSettings::MIN_SEGMENTS_MIN_EFFORTS,
            $minEfforts ?? app(AppSettings::class)->segmentsMinEfforts(),
        );

        if ($this->effort_count < $threshold) {
            return false;
        }

        if ($this->is_sub_climb && $this->detection_count < $threshold) {
            return false;
        }

        return true;
    }

    /**
     * Scope visible segments that are not yet geocoded and have not had a reverse-geocoding attempt
     * within the configured retry window.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingGeocoding(Builder $query, ?int $minEfforts = null): Builder
    {
        $retryCutoff = CarbonImmutable::now('UTC')->subDays(max(1, (int) config('services.nominatim.retry_failed_days', 7)));

        return $query
            ->visible($minEfforts)
            ->whereNull('geocoded_at')
            ->where(function (Builder $attemptQuery) use ($retryCutoff): void {
                $attemptQuery
                    ->whereNull('geocode_attempted_at')
                    ->orWhere('geocode_attempted_at', '<', $retryCutoff);
            });
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
