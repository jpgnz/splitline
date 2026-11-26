<?php

namespace App\Services\Ingestion;

use App\Jobs\SyncActivityStreamJob;
use App\Jobs\SyncActivityWeatherJob;
use App\Models\Activity;
use App\Models\ActivityStream;
use App\Models\ActivityWeather;
use App\Models\BestEffort;
use App\Models\DailyMetric;
use App\Models\FitnessAgeRecord;
use App\Models\HeartRateZone;
use App\Models\HrvRecord;
use App\Models\SleepRecord;
use App\Models\Vo2MaxRecord;
use App\Models\WeightGoal;
use App\Models\WeightMeasurement;
use App\Services\Garmin\BestEffortCalculator;
use App\Services\Gear\GearService;
use Carbon\CarbonImmutable;
use Splitline\Core\Data\ActivityStreamData;
use Splitline\Core\Data\ActivityWeatherData;
use Splitline\Core\Data\DailyHealthMetricData;
use Splitline\Core\Data\FitnessAgeData;
use Splitline\Core\Data\HeartRateZoneData;
use Splitline\Core\Data\HrvData;
use Splitline\Core\Data\NormalizedActivity;
use Splitline\Core\Data\SleepData;
use Splitline\Core\Data\Vo2MaxData;
use Splitline\Core\Data\WeightGoalData;
use Splitline\Core\Data\WeightMeasurementData;

/**
 * Single-user Eloquent persistence layer for normalized splitline/core telemetry DTOs.
 */
class TelemetryPersistenceService
{
    /**
     * Allowed seeded Best Effort distance keys for outdoor running activities.
     *
     * @var array<string, true>
     */
    private const array RUNNING_SPLIT_DISTANCE_KEYS = [
        '1k' => true,
        '1mi' => true,
        '5k' => true,
        '10k' => true,
    ];

    /**
     * Allowed seeded Best Effort distance keys for outdoor road biking activities.
     *
     * @var array<string, true>
     */
    private const array ROAD_BIKING_SPLIT_DISTANCE_KEYS = [
        '40k' => true,
    ];

    /**
     * Strictly whitelisted FIT sessionSummary columns that stream sync may persist to Activity.
     * Excludes primary summary columns (avg_hr, max_hr, avg_speed, max_speed, avg_power,
     * max_power, avg_cadence, max_cadence) so stream sync never overwrites provider summaries.
     *
     * @var array<string, true>
     */
    private const array STREAM_SESSION_SUMMARY_WHITELIST = [
        'begin_stamina' => true,
        'end_stamina' => true,
        'min_stamina' => true,
        'workout_feel' => true,
        'workout_rpe' => true,
        'execution_score' => true,
        'begin_body_battery' => true,
        'end_body_battery' => true,
        'avg_grade_adjusted_speed' => true,
        'avg_vertical_oscillation' => true,
        'avg_vertical_ratio' => true,
        'avg_ground_contact_time' => true,
        'bmr_calories' => true,
        'sweat_loss_ml' => true,
        'avg_respiration_rate' => true,
        'max_respiration_rate' => true,
        'min_respiration_rate' => true,
        'avg_temperature' => true,
        'max_temperature' => true,
        'min_temperature' => true,
        'wind_enabled' => true,
        'total_work' => true,
        'avg_left_torque_effectiveness' => true,
        'avg_right_torque_effectiveness' => true,
        'avg_left_pedal_smoothness' => true,
        'avg_right_pedal_smoothness' => true,
        'threshold_power' => true,
        'power_zone_bounds' => true,
        'workout_meta' => true,
        'run_duration_seconds' => true,
        'walk_duration_seconds' => true,
        'idle_duration_seconds' => true,
    ];

    /**
     * DailyMetric columns populated by separate endpoints that a full daily summary must not clear with null.
     *
     * @var list<string>
     */
    private const array SEPARATE_ENDPOINT_DAILY_METRIC_FIELDS = [
        'hydration_ml',
        'heart_rate_series',
        'body_battery_series',
    ];

    public function __construct(
        private readonly BestEffortCalculator $bestEffortCalculator,
        private readonly GearService $gearService = new GearService,
    ) {}

    /**
     * Upsert a normalized activity summary, seed split-based Best Efforts, assign default gear,
     * and optionally dispatch asynchronous stream/weather enrichment jobs.
     */
    public function upsertActivity(NormalizedActivity $dto, bool $dispatchEnrichments = true): Activity
    {
        $existing = Activity::query()
            ->where('provider', $dto->provider)
            ->where('external_id', $dto->externalId)
            ->first();

        $garminActivityId = ($dto->provider === 'garmin_personal' && ctype_digit($dto->externalId))
            ? (int) $dto->externalId
            : null;

        if ($existing === null && $garminActivityId !== null) {
            $existing = Activity::query()
                ->where('garmin_activity_id', $garminActivityId)
                ->first();
        }

        $isNew = $existing === null;

        $attributes = [
            'provider' => $dto->provider,
            'external_id' => $dto->externalId,
            'garmin_activity_id' => $garminActivityId ?? $existing?->garmin_activity_id,
            'name' => $dto->name,
            'activity_type_key' => $dto->activityTypeKey,
            'sport_category' => $dto->sportCategory,
            'sport_discipline' => $dto->sportDiscipline,
            'is_indoor' => $dto->isIndoor,
            'started_at_local' => $dto->startedAtLocal,
            'started_at_utc' => $dto->startedAtUtc,
            'location_name' => $dto->locationName,
            'start_lat' => $dto->startLat ?? $existing?->start_lat,
            'start_lng' => $dto->startLng ?? $existing?->start_lng,
            'distance_meters' => $dto->distanceMeters,
            'duration_seconds' => $dto->durationSeconds,
            'moving_duration_seconds' => $dto->movingDurationSeconds,
            'elapsed_duration_seconds' => $dto->elapsedDurationSeconds,
            'elevation_gain' => $dto->elevationGain,
            'elevation_loss' => $dto->elevationLoss,
            'min_elevation' => $dto->minElevation,
            'max_elevation' => $dto->maxElevation,
            'avg_speed' => $dto->avgSpeed,
            'max_speed' => $dto->maxSpeed,
            'avg_grade_adjusted_speed' => $dto->avgGradeAdjustedSpeed ?? $existing?->avg_grade_adjusted_speed,
            'calories' => $dto->calories,
            'bmr_calories' => $dto->bmrCalories ?? $existing?->bmr_calories,
            'sweat_loss_ml' => $dto->sweatLossMl ?? $existing?->sweat_loss_ml,
            'avg_hr' => $dto->avgHr,
            'max_hr' => $dto->maxHr,
            'avg_cadence' => $dto->avgCadence,
            'max_cadence' => $dto->maxCadence,
            'avg_stride_length' => $dto->avgStrideLength,
            'avg_vertical_oscillation' => $dto->avgVerticalOscillation ?? $existing?->avg_vertical_oscillation,
            'avg_vertical_ratio' => $dto->avgVerticalRatio ?? $existing?->avg_vertical_ratio,
            'avg_ground_contact_time' => $dto->avgGroundContactTime ?? $existing?->avg_ground_contact_time,
            'steps' => $dto->steps,
            'aerobic_te' => $dto->aerobicTe,
            'anaerobic_te' => $dto->anaerobicTe,
            'training_load' => $dto->trainingLoad,
            'training_effect_label' => $dto->trainingEffectLabel,
            'aerobic_te_message' => $dto->aerobicTeMessage ?? $existing?->aerobic_te_message,
            'anaerobic_te_message' => $dto->anaerobicTeMessage ?? $existing?->anaerobic_te_message,
            'body_battery_diff' => $dto->bodyBatteryDiff,
            'begin_body_battery' => $dto->beginBodyBattery ?? $existing?->begin_body_battery,
            'end_body_battery' => $dto->endBodyBattery ?? $existing?->end_body_battery,
            'hr_zone_1_seconds' => $dto->hrZone1Seconds,
            'hr_zone_2_seconds' => $dto->hrZone2Seconds,
            'hr_zone_3_seconds' => $dto->hrZone3Seconds,
            'hr_zone_4_seconds' => $dto->hrZone4Seconds,
            'hr_zone_5_seconds' => $dto->hrZone5Seconds,
            'avg_power' => $dto->avgPower,
            'max_power' => $dto->maxPower,
            'normalized_power' => $dto->normalizedPower,
            'ftp' => $dto->ftp,
            'threshold_power' => $dto->thresholdPower ?? $existing?->threshold_power,
            'tss' => $dto->tss,
            'intensity_factor' => $dto->intensityFactor,
            'total_work' => $dto->totalWork ?? $existing?->total_work,
            'left_balance' => $dto->leftBalance,
            'avg_left_torque_effectiveness' => $dto->avgLeftTorqueEffectiveness ?? $existing?->avg_left_torque_effectiveness,
            'avg_right_torque_effectiveness' => $dto->avgRightTorqueEffectiveness ?? $existing?->avg_right_torque_effectiveness,
            'avg_left_pedal_smoothness' => $dto->avgLeftPedalSmoothness ?? $existing?->avg_left_pedal_smoothness,
            'avg_right_pedal_smoothness' => $dto->avgRightPedalSmoothness ?? $existing?->avg_right_pedal_smoothness,
            'max_20min_power' => $dto->max20minPower,
            'power_zone_1_seconds' => $dto->powerZone1Seconds,
            'power_zone_2_seconds' => $dto->powerZone2Seconds,
            'power_zone_3_seconds' => $dto->powerZone3Seconds,
            'power_zone_4_seconds' => $dto->powerZone4Seconds,
            'power_zone_5_seconds' => $dto->powerZone5Seconds,
            'power_zone_6_seconds' => $dto->powerZone6Seconds,
            'power_zone_7_seconds' => $dto->powerZone7Seconds,
            'power_zone_bounds' => $dto->powerZoneBounds ?? $existing?->power_zone_bounds,
            'power_curve' => $dto->powerCurve,
            'wind_enabled' => $dto->windEnabled ?? $existing?->wind_enabled,
            'grit' => $dto->grit,
            'avg_flow' => $dto->avgFlow,
            'jump_count' => $dto->jumpCount,
            'unpaved_pct' => $dto->unpavedPct,
            'workout_feel' => $dto->workoutFeel ?? $existing?->workout_feel,
            'workout_rpe' => $dto->workoutRpe ?? $existing?->workout_rpe,
            'execution_score' => $dto->executionScore ?? $existing?->execution_score,
            'workout_meta' => $dto->workoutMeta ?? $existing?->workout_meta,
            'begin_stamina' => $dto->beginStamina ?? $existing?->begin_stamina,
            'end_stamina' => $dto->endStamina ?? $existing?->end_stamina,
            'min_stamina' => $dto->minStamina ?? $existing?->min_stamina,
            'avg_respiration_rate' => $dto->avgRespirationRate ?? $existing?->avg_respiration_rate,
            'min_respiration_rate' => $dto->minRespirationRate ?? $existing?->min_respiration_rate,
            'max_respiration_rate' => $dto->maxRespirationRate ?? $existing?->max_respiration_rate,
            'avg_temperature' => $dto->avgTemperature ?? $existing?->avg_temperature,
            'min_temperature' => $dto->minTemperature ?? $existing?->min_temperature,
            'max_temperature' => $dto->maxTemperature ?? $existing?->max_temperature,
            'moderate_intensity_minutes' => $dto->moderateIntensityMinutes ?? $existing?->moderate_intensity_minutes,
            'vigorous_intensity_minutes' => $dto->vigorousIntensityMinutes ?? $existing?->vigorous_intensity_minutes,
            'run_duration_seconds' => $dto->runDurationSeconds ?? $existing?->run_duration_seconds,
            'walk_duration_seconds' => $dto->walkDurationSeconds ?? $existing?->walk_duration_seconds,
            'idle_duration_seconds' => $dto->idleDurationSeconds ?? $existing?->idle_duration_seconds,
            'is_favorite' => $dto->isFavorite,
            'is_pr' => $dto->isPr,
            'is_manual' => $dto->isManual,
            'raw_summary' => $dto->rawSummary ?? $existing?->raw_summary,
        ];

        if ($existing !== null) {
            $existing->update($attributes);
            $activity = $existing;
        } else {
            $activity = Activity::query()->create($attributes);
        }

        $this->seedBestEffortsFromSplits($activity, $dto->seededSplits);
        $this->gearService->autoAssignDefaultGear($activity);

        if ($dispatchEnrichments && $isNew && ! $activity->is_manual) {
            if (
                $dto->provider === 'garmin_personal'
                && (bool) config('services.garmin.auto_sync_streams', true)
            ) {
                SyncActivityStreamJob::dispatch($activity->id);
            }

            if (
                ! $activity->is_indoor
                && $activity->start_lat !== null
                && $activity->start_lng !== null
                && (bool) config('services.open_meteo.enabled', true)
                && (bool) config('services.open_meteo.auto_sync', true)
            ) {
                SyncActivityWeatherJob::dispatch($activity->id);
            }
        }

        return $activity;
    }

    /**
     * Upsert a parsed activity telemetry stream, recalculate stream-based Best Efforts,
     * merge whitelisted session summary telemetry onto the Activity, and dispatch weather upgrades.
     *
     * @param  array<string, mixed>  $extendedRawSummary
     */
    public function upsertStream(
        Activity $activity,
        ActivityStreamData $stream,
        array $extendedRawSummary = [],
    ): ActivityStream {
        $activityStream = ActivityStream::query()->updateOrCreate(
            ['activity_id' => $activity->id],
            [
                'series' => $stream->series,
                'polyline' => $stream->polyline,
                'laps' => $stream->laps,
                'km_splits' => $stream->kmSplits,
                'run_walk_segments' => $stream->runWalkSegments !== [] ? $stream->runWalkSegments : null,
                'jumps' => $stream->jumps !== [] ? $stream->jumps : null,
            ],
        );

        $this->bestEffortCalculator->syncForActivity($activity, $stream->records);

        $activityUpdates = [
            'streams_synced_at' => CarbonImmutable::now('UTC'),
            'streams_unavailable_at' => null,
        ];

        foreach ($extendedRawSummary as $column => $value) {
            if ($value !== null) {
                $activityUpdates[$column] = $value;
            }
        }

        foreach ($stream->sessionSummary as $column => $value) {
            if ($value === null || ! isset(self::STREAM_SESSION_SUMMARY_WHITELIST[$column])) {
                continue;
            }

            if ($column === 'total_work' && $activity->total_work !== null) {
                continue;
            }

            $activityUpdates[$column] = $value;
        }

        if ($stream->polyline !== []) {
            if ($activity->start_lat === null) {
                $activityUpdates['start_lat'] = $stream->polyline[0][0];
            }

            if ($activity->start_lng === null) {
                $activityUpdates['start_lng'] = $stream->polyline[0][1];
            }
        }

        $activity->update($activityUpdates);

        if (
            ! $activity->is_indoor
            && (($activity->start_lat !== null && $activity->start_lng !== null) || $stream->polyline !== [])
            && (bool) config('services.open_meteo.enabled', true)
            && (bool) config('services.open_meteo.auto_sync', true)
        ) {
            $existingSamplingMode = ActivityWeather::query()
                ->where('activity_id', $activity->id)
                ->value('sampling_mode');

            if ($existingSamplingMode === null || $existingSamplingMode === 'start_point') {
                SyncActivityWeatherJob::dispatch($activity->id, force: $existingSamplingMode === 'start_point');
            }
        }

        return $activityStream;
    }

    /**
     * Upsert a full or partial daily health metric DTO for a provider and calendar date.
     */
    public function upsertDailyMetric(DailyHealthMetricData $dto): bool
    {
        $payload = $dto->toArray();
        unset($payload['provider'], $payload['calendar_date']);

        $fillOnlyWhenNull = array_fill_keys($dto->fillOnlyWhenNullFields, true);

        if ($dto->partialUpdate) {
            $existing = $fillOnlyWhenNull !== []
                ? DailyMetric::query()
                    ->where('provider', $dto->provider)
                    ->where('calendar_date', $dto->calendarDate)
                    ->first()
                : null;

            $attributes = [];

            foreach ($payload as $field => $value) {
                if ($value === null) {
                    continue;
                }

                if (isset($fillOnlyWhenNull[$field]) && $existing !== null && $existing->getAttribute($field) !== null) {
                    continue;
                }

                $attributes[$field] = $value;
            }

            if ($attributes === []) {
                return false;
            }

            DailyMetric::query()->updateOrCreate(
                [
                    'provider' => $dto->provider,
                    'calendar_date' => $dto->calendarDate,
                ],
                $attributes,
            );

            return true;
        }

        if (! $this->hasDailyMetricValues($payload)) {
            return false;
        }

        $existing = null;

        if ($fillOnlyWhenNull !== []) {
            $hasNonNullFillOnly = false;

            foreach ($dto->fillOnlyWhenNullFields as $field) {
                if (($payload[$field] ?? null) !== null) {
                    $hasNonNullFillOnly = true;
                    break;
                }
            }

            if ($hasNonNullFillOnly) {
                $existing = DailyMetric::query()
                    ->where('provider', $dto->provider)
                    ->where('calendar_date', $dto->calendarDate)
                    ->first();
            }
        }

        $attributes = [];

        foreach ($payload as $field => $value) {
            if ($value === null && in_array($field, self::SEPARATE_ENDPOINT_DAILY_METRIC_FIELDS, true)) {
                continue;
            }

            if (isset($fillOnlyWhenNull[$field])) {
                if ($value === null) {
                    continue;
                }

                if ($existing !== null && $existing->getAttribute($field) !== null) {
                    continue;
                }
            }

            $attributes[$field] = $value;
        }

        DailyMetric::query()->updateOrCreate(
            [
                'provider' => $dto->provider,
                'calendar_date' => $dto->calendarDate,
            ],
            $attributes,
        );

        return true;
    }

    /**
     * Upsert a sleep record, stripping null values and preserving richer raw payloads.
     */
    public function upsertSleep(SleepData $dto): bool
    {
        $candidateUpdates = [
            'sleep_seconds' => $dto->sleepSeconds,
            'deep_seconds' => $dto->deepSeconds,
            'light_seconds' => $dto->lightSeconds,
            'rem_seconds' => $dto->remSeconds,
            'awake_seconds' => $dto->awakeSeconds,
            'nap_seconds' => $dto->napSeconds,
            'sleep_start_local' => $dto->sleepStartLocal,
            'sleep_end_local' => $dto->sleepEndLocal,
            'sleep_score' => $dto->sleepScore,
            'sleep_need_minutes' => $dto->sleepNeedMinutes,
            'next_sleep_need_minutes' => $dto->nextSleepNeedMinutes,
        ];

        $updates = array_filter(
            $candidateUpdates,
            static fn (mixed $value): bool => $value !== null,
        );

        if ($updates === []) {
            return false;
        }

        $existing = SleepRecord::query()
            ->where('provider', $dto->provider)
            ->where('calendar_date', $dto->calendarDate)
            ->first();

        if ($dto->rawPayload !== null && ($existing === null || $dto->sleepSeconds !== null)) {
            $updates['raw_payload'] = $dto->rawPayload;
        }

        SleepRecord::query()->updateOrCreate(
            [
                'provider' => $dto->provider,
                'calendar_date' => $dto->calendarDate,
            ],
            $updates,
        );

        return true;
    }

    /**
     * Upsert a daily HRV record.
     */
    public function upsertHrv(HrvData $dto): bool
    {
        if (
            $dto->weeklyAvg === null
            && $dto->lastNightAvg === null
            && $dto->lastNight5minHigh === null
            && $dto->status === null
        ) {
            return false;
        }

        HrvRecord::query()->updateOrCreate(
            [
                'provider' => $dto->provider,
                'calendar_date' => $dto->calendarDate,
            ],
            [
                'weekly_avg' => $dto->weeklyAvg,
                'last_night_avg' => $dto->lastNightAvg,
                'last_night_5min_high' => $dto->lastNight5minHigh,
                'status' => $dto->status,
                'baseline_low_upper' => $dto->baselineLowUpper,
                'baseline_balanced_low' => $dto->baselineBalancedLow,
                'baseline_balanced_upper' => $dto->baselineBalancedUpper,
                'feedback_phrase' => $dto->feedbackPhrase,
            ],
        );

        return true;
    }

    /**
     * Upsert a daily VO2 max record.
     */
    public function upsertVo2Max(Vo2MaxData $dto): bool
    {
        if ($dto->vo2MaxPrecise === null && $dto->vo2Max === null) {
            return false;
        }

        Vo2MaxRecord::query()->updateOrCreate(
            [
                'provider' => $dto->provider,
                'calendar_date' => $dto->calendarDate,
                'sport' => $dto->sport,
            ],
            [
                'vo2_max_precise' => $dto->vo2MaxPrecise,
                'vo2_max' => $dto->vo2Max,
                'sub_sport' => $dto->subSport,
            ],
        );

        return true;
    }

    /**
     * Upsert a fitness age record; returns true only when fitness_age itself is populated.
     */
    public function upsertFitnessAge(FitnessAgeData $dto): bool
    {
        if ($dto->chronologicalAge === null && $dto->fitnessAge === null) {
            return false;
        }

        FitnessAgeRecord::query()->updateOrCreate(
            [
                'provider' => $dto->provider,
                'calendar_date' => $dto->calendarDate,
            ],
            [
                'chronological_age' => $dto->chronologicalAge,
                'fitness_age' => $dto->fitnessAge,
                'previous_fitness_age' => $dto->previousFitnessAge,
                'components' => $dto->components,
            ],
        );

        return $dto->fitnessAge !== null;
    }

    /**
     * Upsert a weight measurement record.
     */
    public function upsertWeightMeasurement(WeightMeasurementData $dto): bool
    {
        if ($dto->weightGrams <= 0) {
            return false;
        }

        $garminSamplePk = ($dto->provider === 'garmin_personal' && ctype_digit($dto->externalId))
            ? (int) $dto->externalId
            : null;

        WeightMeasurement::query()->updateOrCreate(
            [
                'provider' => $dto->provider,
                'external_id' => $dto->externalId,
            ],
            [
                'garmin_sample_pk' => $garminSamplePk,
                'calendar_date' => $dto->calendarDate,
                'measured_at' => $dto->measuredAt,
                'weight_grams' => $dto->weightGrams,
                'bmi' => $dto->bmi,
                'body_fat_pct' => $dto->bodyFatPct,
                'body_water_pct' => $dto->bodyWaterPct,
                'bone_mass_grams' => $dto->boneMassGrams,
                'muscle_mass_grams' => $dto->muscleMassGrams,
                'source_type' => $dto->sourceType,
            ],
        );

        return true;
    }

    /**
     * Upsert a weight goal record.
     */
    public function upsertWeightGoal(WeightGoalData $dto): bool
    {
        if ($dto->goalGrams <= 0) {
            return false;
        }

        WeightGoal::query()->updateOrCreate(
            [
                'provider' => $dto->provider,
                'start_date' => $dto->startDate,
            ],
            [
                'end_date' => $dto->endDate,
                'goal_grams' => $dto->goalGrams,
                'source_category' => $dto->sourceCategory,
                'garmin_updated_at' => $dto->updatedAt,
            ],
        );

        return true;
    }

    /**
     * Upsert a heart rate zone configuration for a provider and sport.
     */
    public function upsertHeartRateZone(HeartRateZoneData $dto): bool
    {
        HeartRateZone::query()->updateOrCreate(
            [
                'provider' => $dto->provider,
                'sport' => $dto->sport,
            ],
            [
                'training_method' => $dto->trainingMethod,
                'max_hr' => $dto->maxHr,
                'resting_hr' => $dto->restingHr,
                'lactate_threshold_hr' => $dto->lactateThresholdHr,
                'zone1_floor' => $dto->zone1Floor,
                'zone2_floor' => $dto->zone2Floor,
                'zone3_floor' => $dto->zone3Floor,
                'zone4_floor' => $dto->zone4Floor,
                'zone5_floor' => $dto->zone5Floor,
            ],
        );

        return true;
    }

    /**
     * Upsert weather observations for an activity.
     */
    public function upsertWeather(Activity $activity, ActivityWeatherData $dto): ActivityWeather
    {
        return ActivityWeather::query()->updateOrCreate(
            ['activity_id' => $activity->id],
            [
                ...$dto->toArray(),
                'fetched_at' => CarbonImmutable::now('UTC'),
            ],
        );
    }

    /**
     * Seed Best Effort rows from provider-reported fastest splits without overriding fit_stream efforts.
     *
     * @param  list<array{distance_key: string, distance_meters: float, elapsed_seconds: float, source: string}>  $seededSplits
     */
    private function seedBestEffortsFromSplits(Activity $activity, array $seededSplits): void
    {
        if ($seededSplits === []) {
            return;
        }

        $sport = null;
        $allowedKeys = [];

        if ($activity->sport_category === 'running' && ! $activity->is_indoor) {
            $sport = 'running';
            $allowedKeys = self::RUNNING_SPLIT_DISTANCE_KEYS;
        } elseif ($activity->sport_category === 'biking' && ! $activity->is_indoor && $activity->effectiveDiscipline() === 'road') {
            $sport = 'road_biking';
            $allowedKeys = self::ROAD_BIKING_SPLIT_DISTANCE_KEYS;
        }

        if ($sport === null) {
            return;
        }

        $activityId = $activity->getKey();

        foreach ($seededSplits as $split) {
            $distanceKey = $split['distance_key'];
            $elapsedSeconds = $split['elapsed_seconds'];

            if (! isset($allowedKeys[$distanceKey]) || $elapsedSeconds <= 0.0) {
                continue;
            }

            $existing = BestEffort::query()
                ->where('activity_id', $activityId)
                ->where('sport', $sport)
                ->where('distance_key', $distanceKey)
                ->first();

            if ($existing !== null) {
                if ($existing->getAttribute('source') === 'fit_stream') {
                    continue;
                }

                $existing->update([
                    'distance_meters' => $split['distance_meters'],
                    'elapsed_seconds' => $elapsedSeconds,
                    'achieved_at' => $activity->started_at_local,
                    'source' => $split['source'],
                ]);

                continue;
            }

            BestEffort::query()->create([
                'activity_id' => $activityId,
                'sport' => $sport,
                'distance_key' => $distanceKey,
                'distance_meters' => $split['distance_meters'],
                'elapsed_seconds' => $elapsedSeconds,
                'achieved_at' => $activity->started_at_local,
                'source' => $split['source'],
                'is_excluded' => false,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function hasDailyMetricValues(array $attributes): bool
    {
        $positiveKeys = [
            'steps',
            'distance_meters',
            'active_calories',
            'moderate_intensity_minutes',
            'vigorous_intensity_minutes',
            'floors_ascended',
        ];

        foreach ($positiveKeys as $key) {
            $value = $attributes[$key] ?? null;

            if ((is_int($value) || is_float($value)) && $value > 0) {
                return true;
            }
        }

        $presentKeys = [
            'resting_hr',
            'min_hr',
            'max_hr',
            'avg_stress',
            'body_battery_current',
            'body_battery_high',
            'body_battery_low',
            'body_battery_charged',
            'body_battery_drained',
            'avg_respiration',
            'avg_spo2',
        ];

        foreach ($presentKeys as $key) {
            if (($attributes[$key] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }
}
