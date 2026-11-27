<?php

namespace App\Models;

use App\Models\Scopes\NotExcludedScope;
use Carbon\CarbonImmutable;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * @property int $id
 * @property string $provider
 * @property ?string $external_id
 * @property ?int $garmin_activity_id
 * @property string $name
 * @property string|null $garmin_name
 * @property string|null $name_override
 * @property string $activity_type_key
 * @property string $sport_category
 * @property string|null $garmin_sport_category
 * @property string|null $sport_category_override
 * @property string|null $sport_discipline
 * @property string|null $discipline_override
 * @property bool $is_indoor
 * @property CarbonImmutable $started_at_local
 * @property CarbonImmutable $started_at_utc
 * @property string|null $location_name
 * @property CarbonImmutable|null $location_geocoded_at
 * @property CarbonImmutable|null $geocode_attempted_at
 * @property float|null $start_lat
 * @property float|null $start_lng
 * @property float $distance_meters
 * @property float $duration_seconds
 * @property float|null $moving_duration_seconds
 * @property float|null $elapsed_duration_seconds
 * @property float|null $elevation_gain
 * @property float|null $elevation_loss
 * @property float|null $min_elevation
 * @property float|null $max_elevation
 * @property float|null $avg_speed
 * @property float|null $max_speed
 * @property float|null $avg_grade_adjusted_speed
 * @property int|null $calories
 * @property int|null $bmr_calories
 * @property int|null $sweat_loss_ml
 * @property int|null $avg_hr
 * @property int|null $max_hr
 * @property float|null $avg_cadence
 * @property float|null $max_cadence
 * @property float|null $avg_stride_length
 * @property float|null $avg_vertical_oscillation
 * @property float|null $avg_vertical_ratio
 * @property float|null $avg_ground_contact_time
 * @property int|null $steps
 * @property float|null $aerobic_te
 * @property float|null $anaerobic_te
 * @property float|null $training_load
 * @property string|null $training_effect_label
 * @property string|null $aerobic_te_message
 * @property string|null $anaerobic_te_message
 * @property int|null $body_battery_diff
 * @property int|null $begin_body_battery
 * @property int|null $end_body_battery
 * @property float|null $hr_zone_1_seconds
 * @property float|null $hr_zone_2_seconds
 * @property float|null $hr_zone_3_seconds
 * @property float|null $hr_zone_4_seconds
 * @property float|null $hr_zone_5_seconds
 * @property float|null $avg_power
 * @property float|null $max_power
 * @property float|null $normalized_power
 * @property float|null $ftp
 * @property float|null $threshold_power
 * @property float|null $tss
 * @property float|null $intensity_factor
 * @property float|null $total_work
 * @property float|null $left_balance
 * @property float|null $avg_left_torque_effectiveness
 * @property float|null $avg_right_torque_effectiveness
 * @property float|null $avg_left_pedal_smoothness
 * @property float|null $avg_right_pedal_smoothness
 * @property float|null $max_20min_power
 * @property float|null $power_zone_1_seconds
 * @property float|null $power_zone_2_seconds
 * @property float|null $power_zone_3_seconds
 * @property float|null $power_zone_4_seconds
 * @property float|null $power_zone_5_seconds
 * @property float|null $power_zone_6_seconds
 * @property float|null $power_zone_7_seconds
 * @property list<int>|null $power_zone_bounds
 * @property array<int|string, mixed>|null $power_curve
 * @property bool|null $wind_enabled
 * @property float|null $grit
 * @property float|null $avg_flow
 * @property int|null $jump_count
 * @property float|null $unpaved_pct
 * @property int|null $workout_feel
 * @property int|null $workout_rpe
 * @property int|null $execution_score
 * @property array<string, mixed>|null $workout_meta
 * @property int|null $begin_stamina
 * @property int|null $end_stamina
 * @property int|null $min_stamina
 * @property float|null $avg_respiration_rate
 * @property float|null $min_respiration_rate
 * @property float|null $max_respiration_rate
 * @property float|null $avg_temperature
 * @property float|null $min_temperature
 * @property float|null $max_temperature
 * @property int|null $moderate_intensity_minutes
 * @property int|null $vigorous_intensity_minutes
 * @property float|null $run_duration_seconds
 * @property float|null $walk_duration_seconds
 * @property float|null $idle_duration_seconds
 * @property bool $is_favorite
 * @property bool $garmin_is_favorite
 * @property bool|null $favorite_override
 * @property bool $is_pr
 * @property bool $is_manual
 * @property CarbonImmutable|null $excluded_at
 * @property CarbonImmutable|null $streams_synced_at
 * @property CarbonImmutable|null $streams_unavailable_at
 * @property CarbonImmutable|null $route_match_skipped_at
 * @property CarbonImmutable|null $segments_scanned_at
 * @property array<int|string, mixed>|null $raw_summary
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ActivityStream|null $stream
 * @property-read ActivityWeather|null $weather
 * @property-read ActivityDerivedMetric|null $derivedMetrics
 * @property-read ActivityRouteFingerprint|null $routeFingerprint
 * @property-read Collection<int, BestEffort> $bestEfforts
 * @property-read Collection<int, SegmentEffort> $segmentEfforts
 * @property-read Collection<int, Segment> $detectedSegments
 */
#[ScopedBy([NotExcludedScope::class])]
class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'external_id',
        'garmin_activity_id',
        'name',
        'garmin_name',
        'name_override',
        'activity_type_key',
        'sport_category',
        'garmin_sport_category',
        'sport_category_override',
        'sport_discipline',
        'discipline_override',
        'is_indoor',
        'started_at_local',
        'started_at_utc',
        'location_name',
        'location_geocoded_at',
        'geocode_attempted_at',
        'start_lat',
        'start_lng',
        'distance_meters',
        'duration_seconds',
        'moving_duration_seconds',
        'elapsed_duration_seconds',
        'elevation_gain',
        'elevation_loss',
        'min_elevation',
        'max_elevation',
        'avg_speed',
        'max_speed',
        'avg_grade_adjusted_speed',
        'calories',
        'bmr_calories',
        'sweat_loss_ml',
        'avg_hr',
        'max_hr',
        'avg_cadence',
        'max_cadence',
        'avg_stride_length',
        'avg_vertical_oscillation',
        'avg_vertical_ratio',
        'avg_ground_contact_time',
        'steps',
        'aerobic_te',
        'anaerobic_te',
        'training_load',
        'training_effect_label',
        'aerobic_te_message',
        'anaerobic_te_message',
        'body_battery_diff',
        'begin_body_battery',
        'end_body_battery',
        'hr_zone_1_seconds',
        'hr_zone_2_seconds',
        'hr_zone_3_seconds',
        'hr_zone_4_seconds',
        'hr_zone_5_seconds',
        'avg_power',
        'max_power',
        'normalized_power',
        'ftp',
        'threshold_power',
        'tss',
        'intensity_factor',
        'total_work',
        'left_balance',
        'avg_left_torque_effectiveness',
        'avg_right_torque_effectiveness',
        'avg_left_pedal_smoothness',
        'avg_right_pedal_smoothness',
        'max_20min_power',
        'power_zone_1_seconds',
        'power_zone_2_seconds',
        'power_zone_3_seconds',
        'power_zone_4_seconds',
        'power_zone_5_seconds',
        'power_zone_6_seconds',
        'power_zone_7_seconds',
        'power_zone_bounds',
        'power_curve',
        'wind_enabled',
        'grit',
        'avg_flow',
        'jump_count',
        'unpaved_pct',
        'workout_feel',
        'workout_rpe',
        'execution_score',
        'workout_meta',
        'begin_stamina',
        'end_stamina',
        'min_stamina',
        'avg_respiration_rate',
        'min_respiration_rate',
        'max_respiration_rate',
        'avg_temperature',
        'min_temperature',
        'max_temperature',
        'moderate_intensity_minutes',
        'vigorous_intensity_minutes',
        'run_duration_seconds',
        'walk_duration_seconds',
        'idle_duration_seconds',
        'is_favorite',
        'garmin_is_favorite',
        'favorite_override',
        'is_pr',
        'is_manual',
        'excluded_at',
        'streams_synced_at',
        'streams_unavailable_at',
        'route_match_skipped_at',
        'segments_scanned_at',
        'raw_summary',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'garmin_activity_id' => 'integer',
            'is_indoor' => 'boolean',
            'started_at_local' => 'immutable_datetime',
            'started_at_utc' => 'immutable_datetime',
            'start_lat' => 'float',
            'start_lng' => 'float',
            'distance_meters' => 'float',
            'duration_seconds' => 'float',
            'moving_duration_seconds' => 'float',
            'elapsed_duration_seconds' => 'float',
            'elevation_gain' => 'float',
            'elevation_loss' => 'float',
            'min_elevation' => 'float',
            'max_elevation' => 'float',
            'avg_speed' => 'float',
            'max_speed' => 'float',
            'avg_grade_adjusted_speed' => 'float',
            'calories' => 'integer',
            'bmr_calories' => 'integer',
            'sweat_loss_ml' => 'integer',
            'avg_hr' => 'integer',
            'max_hr' => 'integer',
            'avg_cadence' => 'float',
            'max_cadence' => 'float',
            'avg_stride_length' => 'float',
            'avg_vertical_oscillation' => 'float',
            'avg_vertical_ratio' => 'float',
            'avg_ground_contact_time' => 'float',
            'steps' => 'integer',
            'aerobic_te' => 'float',
            'anaerobic_te' => 'float',
            'training_load' => 'float',
            'body_battery_diff' => 'integer',
            'begin_body_battery' => 'integer',
            'end_body_battery' => 'integer',
            'hr_zone_1_seconds' => 'float',
            'hr_zone_2_seconds' => 'float',
            'hr_zone_3_seconds' => 'float',
            'hr_zone_4_seconds' => 'float',
            'hr_zone_5_seconds' => 'float',
            'avg_power' => 'float',
            'max_power' => 'float',
            'normalized_power' => 'float',
            'ftp' => 'float',
            'threshold_power' => 'float',
            'tss' => 'float',
            'intensity_factor' => 'float',
            'total_work' => 'float',
            'left_balance' => 'float',
            'avg_left_torque_effectiveness' => 'float',
            'avg_right_torque_effectiveness' => 'float',
            'avg_left_pedal_smoothness' => 'float',
            'avg_right_pedal_smoothness' => 'float',
            'max_20min_power' => 'float',
            'power_zone_1_seconds' => 'float',
            'power_zone_2_seconds' => 'float',
            'power_zone_3_seconds' => 'float',
            'power_zone_4_seconds' => 'float',
            'power_zone_5_seconds' => 'float',
            'power_zone_6_seconds' => 'float',
            'power_zone_7_seconds' => 'float',
            'power_zone_bounds' => 'array',
            'power_curve' => 'array',
            'wind_enabled' => 'boolean',
            'grit' => 'float',
            'avg_flow' => 'float',
            'jump_count' => 'integer',
            'unpaved_pct' => 'float',
            'workout_feel' => 'integer',
            'workout_rpe' => 'integer',
            'execution_score' => 'integer',
            'workout_meta' => 'array',
            'begin_stamina' => 'integer',
            'end_stamina' => 'integer',
            'min_stamina' => 'integer',
            'avg_respiration_rate' => 'float',
            'min_respiration_rate' => 'float',
            'max_respiration_rate' => 'float',
            'avg_temperature' => 'float',
            'min_temperature' => 'float',
            'max_temperature' => 'float',
            'moderate_intensity_minutes' => 'integer',
            'vigorous_intensity_minutes' => 'integer',
            'run_duration_seconds' => 'float',
            'walk_duration_seconds' => 'float',
            'idle_duration_seconds' => 'float',
            'is_favorite' => 'boolean',
            'garmin_is_favorite' => 'boolean',
            'favorite_override' => 'boolean',
            'is_pr' => 'boolean',
            'is_manual' => 'boolean',
            'excluded_at' => 'immutable_datetime',
            'streams_synced_at' => 'immutable_datetime',
            'streams_unavailable_at' => 'immutable_datetime',
            'route_match_skipped_at' => 'immutable_datetime',
            'location_geocoded_at' => 'immutable_datetime',
            'geocode_attempted_at' => 'immutable_datetime',
            'segments_scanned_at' => 'immutable_datetime',
            'raw_summary' => 'array',
        ];
    }

    /**
     * @return HasOne<ActivityStream, $this>
     */
    public function stream(): HasOne
    {
        return $this->hasOne(ActivityStream::class);
    }

    /**
     * @return HasOne<ActivityWeather, $this>
     */
    public function weather(): HasOne
    {
        return $this->hasOne(ActivityWeather::class);
    }

    /**
     * @return HasOne<ActivityDerivedMetric, $this>
     */
    public function derivedMetrics(): HasOne
    {
        return $this->hasOne(ActivityDerivedMetric::class);
    }

    /**
     * @return HasOne<ActivityRouteFingerprint, $this>
     */
    public function routeFingerprint(): HasOne
    {
        return $this->hasOne(ActivityRouteFingerprint::class);
    }

    /**
     * @return HasMany<BestEffort, $this>
     */
    public function bestEfforts(): HasMany
    {
        return $this->hasMany(BestEffort::class);
    }

    /**
     * @return HasMany<SegmentEffort, $this>
     */
    public function segmentEfforts(): HasMany
    {
        return $this->hasMany(SegmentEffort::class);
    }

    /**
     * Auto-detected climbs the climb detector found in this activity's stream.
     *
     * @return BelongsToMany<Segment, $this>
     */
    public function detectedSegments(): BelongsToMany
    {
        return $this->belongsToMany(Segment::class, 'segment_detections')->withTimestamps();
    }

    /**
     * @return BelongsToMany<GearItem, $this>
     */
    public function gearItems(): BelongsToMany
    {
        return $this->belongsToMany(GearItem::class, 'activity_gear')->withTimestamps();
    }

    public function effectiveDiscipline(): ?string
    {
        return $this->discipline_override ?? $this->sport_discipline;
    }

    public function isExcluded(): bool
    {
        return $this->excluded_at !== null;
    }

    /**
     * Resolve route bindings including excluded activities so users can inspect and restore them.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->resolveRouteBindingQuery(static::withExcluded(), $value, $field)->first();
    }

    /**
     * Include user-excluded activities in the query.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithExcluded(Builder $query): Builder
    {
        return $query->withoutGlobalScope(NotExcludedScope::class);
    }

    /**
     * Restrict the query to user-excluded activities only.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOnlyExcluded(Builder $query): Builder
    {
        return $query
            ->withoutGlobalScope(NotExcludedScope::class)
            ->whereNotNull($this->qualifyColumn('excluded_at'));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('sport_category', 'running');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBiking(Builder $query): Builder
    {
        return $query->where('sport_category', 'biking');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRoadBiking(Builder $query): Builder
    {
        return $query
            ->where('sport_category', 'biking')
            ->whereRaw('COALESCE(discipline_override, sport_discipline) = ?', ['road']);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMountainBiking(Builder $query): Builder
    {
        return $query
            ->where('sport_category', 'biking')
            ->whereRaw('COALESCE(discipline_override, sport_discipline) = ?', ['mtb']);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOtherBiking(Builder $query): Builder
    {
        return $query
            ->where('sport_category', 'biking')
            ->whereRaw('COALESCE(discipline_override, sport_discipline) = ?', ['other']);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingStreams(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query
            ->whereNull("{$table}.streams_synced_at")
            ->whereNull("{$table}.streams_unavailable_at");
    }

    /**
     * Outdoor activities with a start coordinate or a stored stream that weather can be sampled along.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithWeatherCoordinates(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query
            ->where("{$table}.is_indoor", false)
            ->where(function (Builder $coordinateQuery) use ($table): void {
                $coordinateQuery
                    ->where(function (Builder $startCoords) use ($table): void {
                        $startCoords
                            ->whereNotNull("{$table}.start_lat")
                            ->whereNotNull("{$table}.start_lng");
                    })
                    ->orWhereExists(function (QueryBuilder $streamSub) use ($table): void {
                        $streamSub
                            ->selectRaw('1')
                            ->from('activity_streams')
                            ->whereColumn('activity_streams.activity_id', "{$table}.id")
                            ->whereNotNull('activity_streams.polyline')
                            ->where('activity_streams.polyline', '!=', '[]');
                    });
            });
    }

    /**
     * Weather-eligible activities that have no weather yet, or only start-point weather that can be upgraded to route sampling.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingWeather(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query
            ->withWeatherCoordinates()
            ->where(function (Builder $missingOrUpgradeQuery) use ($table): void {
                $missingOrUpgradeQuery
                    ->whereNotExists(function (QueryBuilder $weatherSub) use ($table): void {
                        $weatherSub
                            ->selectRaw('1')
                            ->from('activity_weathers')
                            ->whereColumn('activity_weathers.activity_id', "{$table}.id");
                    })
                    ->orWhere(function (Builder $upgradeQuery) use ($table): void {
                        $upgradeQuery
                            ->whereExists(function (QueryBuilder $weatherSub) use ($table): void {
                                $weatherSub
                                    ->selectRaw('1')
                                    ->from('activity_weathers')
                                    ->whereColumn('activity_weathers.activity_id', "{$table}.id")
                                    ->where('activity_weathers.sampling_mode', 'start_point');
                            })
                            ->whereExists(function (QueryBuilder $streamSub) use ($table): void {
                                $streamSub
                                    ->selectRaw('1')
                                    ->from('activity_streams')
                                    ->whereColumn('activity_streams.activity_id', "{$table}.id")
                                    ->whereNotNull('activity_streams.polyline')
                                    ->where('activity_streams.polyline', '!=', '[]');
                            });
                    });
            });
    }

    /**
     * Scope outdoor activities with start coordinates that still lack a location name and have not had a
     * reverse-geocoding attempt within the configured retry window.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingGeocoding(Builder $query): Builder
    {
        $table = $this->getTable();
        $retryCutoff = CarbonImmutable::now('UTC')->subDays(max(1, (int) config('services.nominatim.retry_failed_days', 7)));

        return $query
            ->where("{$table}.is_indoor", false)
            ->whereNotNull("{$table}.start_lat")
            ->whereNotNull("{$table}.start_lng")
            ->where(function (Builder $locationQuery) use ($table): void {
                $locationQuery
                    ->whereNull("{$table}.location_name")
                    ->orWhere("{$table}.location_name", '');
            })
            ->where(function (Builder $attemptQuery) use ($table, $retryCutoff): void {
                $attemptQuery
                    ->whereNull("{$table}.geocode_attempted_at")
                    ->orWhere("{$table}.geocode_attempted_at", '<', $retryCutoff);
            });
    }
}
