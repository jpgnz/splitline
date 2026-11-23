<?php

namespace App\Services\Garmin;

use App\Jobs\SyncActivityStreamJob;
use App\Jobs\SyncActivityWeatherJob;
use App\Models\Activity;
use App\Models\BestEffort;
use App\Models\DailyMetric;
use App\Models\FitnessAgeRecord;
use App\Models\HeartRateZone;
use App\Models\HrvRecord;
use App\Models\SleepRecord;
use App\Models\SyncRun;
use App\Models\Vo2MaxRecord;
use App\Models\WeightGoal;
use App\Models\WeightMeasurement;
use App\Services\Gear\GearService;
use Carbon\CarbonImmutable;
use Throwable;

class GarminSyncService
{
    private const int MAX_INTRADAY_SERIES_POINTS = 288;

    private const int INCREMENTAL_ACTIVITY_PAGE_SIZE = 50;

    private const int INITIAL_ACTIVITY_LIMIT = 100;

    private const int BACKFILL_ACTIVITY_PAGE_SIZE = 100;

    private const int BACKFILL_WINDOW_DAYS = 28;

    private const int BACKFILL_FITNESS_AGE_STEP_DAYS = 7;

    private const int MAX_CONSECUTIVE_EMPTY_WINDOWS = 4;

    /**
     * @var list<int>
     */
    private const array POWER_CURVE_DURATIONS = [
        1, 2, 5, 10, 20, 30, 60, 120, 300, 600, 1200, 1800, 3600, 7200,
    ];

    /**
     * @var array<string, array{distance_key: string, distance_meters: float}>
     */
    private const array RUNNING_SPLIT_MAP = [
        'fastestSplit_1000' => ['distance_key' => '1k', 'distance_meters' => 1000.0],
        'fastestSplit_1609' => ['distance_key' => '1mi', 'distance_meters' => 1609.34],
        'fastestSplit_5000' => ['distance_key' => '5k', 'distance_meters' => 5000.0],
        'fastestSplit_10000' => ['distance_key' => '10k', 'distance_meters' => 10000.0],
    ];

    /**
     * @var array<string, array{distance_key: string, distance_meters: float}>
     */
    private const array ROAD_BIKING_SPLIT_MAP = [
        'fastestSplit_40000' => ['distance_key' => '40k', 'distance_meters' => 40000.0],
    ];

    public function __construct(
        private readonly GarminClient $client,
        private readonly ActivityClassifier $classifier,
        private readonly GearService $gearService = new GearService,
    ) {}

    /**
     * Run an incremental sync of recent health metrics, HR zones, and new activities.
     *
     * @param  (callable(string): void)|null  $onProgress
     */
    public function syncIncremental(int $days = 7, ?callable $onProgress = null, ?SyncRun $existingRun = null): SyncRun
    {
        $days = max(1, $days);

        if ($existingRun !== null) {
            $existingRun->update([
                'type' => 'incremental',
                'status' => 'running',
                'finished_at' => null,
                'error_message' => null,
            ]);
            $syncRun = $existingRun;
        } else {
            $syncRun = SyncRun::query()->create([
                'type' => 'incremental',
                'status' => 'running',
                'started_at' => CarbonImmutable::now(),
                'items_synced' => 0,
            ]);
        }

        $itemsSynced = 0;

        if ($onProgress !== null) {
            $this->client->onRateLimitWait(function (float $waitSeconds, string $reason) use ($onProgress): void {
                $seconds = (int) ceil($waitSeconds);
                $this->report($onProgress, "Rate limited ({$reason}) — waiting {$seconds}s");
            });
        }

        try {
            $today = $this->today();
            $todayStr = $today->format('Y-m-d');
            $startStr = $today->subDays($days - 1)->format('Y-m-d');
            $weightStartStr = $today->subDays(29)->format('Y-m-d');

            $this->report($onProgress, 'Syncing heart rate zones');
            $itemsSynced += $this->syncHeartRateZones();

            $this->report($onProgress, "Syncing daily summaries and heart rate ({$startStr} to {$todayStr})");

            for ($offset = 0; $offset < $days; $offset++) {
                $dateStr = $today->subDays($offset)->format('Y-m-d');
                $itemsSynced += $this->syncDailySummary($dateStr);
                $itemsSynced += $this->syncDailyHeartRate($dateStr);
            }

            $this->report($onProgress, "Syncing body battery and hydration ({$startStr} to {$todayStr})");
            $itemsSynced += $this->syncDailyBodyBattery($startStr, $todayStr);
            $itemsSynced += $this->syncDailyHydration($startStr, $todayStr);

            $this->report($onProgress, "Syncing sleep ({$startStr} to {$todayStr})");
            $itemsSynced += $this->syncDailySleepSummaries($startStr, $todayStr);
            $itemsSynced += $this->syncDailySleep($todayStr);
            $itemsSynced += $this->syncDailySleep($today->subDay()->format('Y-m-d'));

            $this->report($onProgress, "Syncing HRV and VO2 max ({$startStr} to {$todayStr})");
            $itemsSynced += $this->syncDailyHrvSummaries($startStr, $todayStr);
            $itemsSynced += $this->syncDailyVo2Max($startStr, $todayStr);

            $this->report($onProgress, "Syncing weight measurements ({$weightStartStr} to {$todayStr})");
            $itemsSynced += $this->syncWeightMeasurements($weightStartStr, $todayStr);
            $itemsSynced += $this->syncWeightGoals($weightStartStr, $todayStr);

            $this->report($onProgress, "Syncing fitness age ({$startStr} to {$todayStr})");
            $itemsSynced += $this->syncFitnessAgeRange(
                $startStr,
                $todayStr,
                stepDays: 1,
                skipExisting: true,
                alwaysRefreshDate: $todayStr,
            );

            $this->report($onProgress, 'Syncing recent activities');
            $itemsSynced += $this->syncRecentActivities();

            $syncRun->update([
                'status' => 'completed',
                'finished_at' => CarbonImmutable::now(),
                'items_synced' => $itemsSynced,
            ]);

            return $syncRun->refresh();
        } catch (Throwable $exception) {
            $syncRun->update([
                'status' => 'failed',
                'finished_at' => CarbonImmutable::now(),
                'items_synced' => $itemsSynced,
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            $this->client->onRateLimitWait(null);
        }
    }

    /**
     * Run a full, resumable all-time backfill of activities and historical health metrics.
     *
     * @param  (callable(string): void)|null  $onProgress
     */
    public function syncBackfill(
        ?string $resumeFromDate = null,
        ?callable $onProgress = null,
        ?string $untilDate = null,
    ): SyncRun {
        $resumable = ($resumeFromDate === null || trim($resumeFromDate) === '')
            ? $this->findResumableBackfillRun()
            : null;

        $cursorDate = $resumable !== null && is_string($resumable->cursor_date)
            ? CarbonImmutable::parse($resumable->cursor_date, $this->timezone())->startOfDay()
            : $this->resolveBackfillCursorDate($resumeFromDate);

        $effectiveUntilDate = ($untilDate !== null && trim($untilDate) !== '')
            ? trim($untilDate)
            : $resumable?->until_date;

        $initialSetupCompleted = $resumable !== null && $resumable->initial_setup_completed;

        SyncRun::supersedeStaleBackfills();

        $syncRun = SyncRun::query()->create([
            'type' => 'backfill',
            'status' => 'running',
            'started_at' => CarbonImmutable::now(),
            'cursor_date' => $cursorDate->format('Y-m-d'),
            'until_date' => $effectiveUntilDate,
            'initial_setup_completed' => $initialSetupCompleted,
            'items_synced' => 0,
        ]);

        $this->syncBackfillChunk(
            syncRun: $syncRun,
            maxWindows: PHP_INT_MAX,
            untilDate: $effectiveUntilDate,
            includeInitialSetup: ! $initialSetupCompleted,
            onProgress: $onProgress,
        );

        return $syncRun->refresh();
    }

    /**
     * Advance a resumable backfill run by up to $maxWindows 28-day windows.
     *
     * @param  (callable(string): void)|null  $onProgress
     * @return array{
     *     completed: bool,
     *     consecutiveEmptyWindows: int,
     *     consecutiveEmptyFitnessAgeWindows: int
     * }
     */
    public function syncBackfillChunk(
        SyncRun $syncRun,
        int $maxWindows = 10,
        ?string $untilDate = null,
        bool $includeInitialSetup = true,
        int $consecutiveEmptyWindows = 0,
        int $consecutiveEmptyFitnessAgeWindows = 0,
        ?callable $onProgress = null,
    ): array {
        $itemsSynced = (int) $syncRun->items_synced;
        $windowLimit = max(1, $maxWindows);
        $effectiveUntilDate = ($untilDate !== null && trim($untilDate) !== '')
            ? trim($untilDate)
            : $syncRun->until_date;

        if ($onProgress !== null) {
            $this->client->onRateLimitWait(function (float $waitSeconds, string $reason) use ($onProgress): void {
                $seconds = (int) ceil($waitSeconds);
                $this->report($onProgress, "Rate limited ({$reason}) — waiting {$seconds}s");
            });
        }

        try {
            if ($includeInitialSetup && ! $syncRun->initial_setup_completed) {
                $this->report($onProgress, 'Syncing heart rate zones');
                $itemsSynced += $this->syncHeartRateZones();

                $this->report($onProgress, 'Backfilling all activities');
                $itemsSynced += $this->syncAllActivities($onProgress);

                $syncRun->update([
                    'initial_setup_completed' => true,
                    'items_synced' => $itemsSynced,
                ]);
            }

            $todayStr = $this->today()->format('Y-m-d');
            $earliestDate = $effectiveUntilDate !== null && trim($effectiveUntilDate) !== ''
                ? CarbonImmutable::parse($effectiveUntilDate, $this->timezone())->startOfDay()
                : $this->resolveEarliestBackfillDate();
            $windowEnd = is_string($syncRun->cursor_date) && $syncRun->cursor_date !== ''
                ? CarbonImmutable::parse($syncRun->cursor_date, $this->timezone())->startOfDay()
                : $this->today();
            $windowsProcessed = 0;

            while (
                $windowsProcessed < $windowLimit
                && $windowEnd->greaterThanOrEqualTo($earliestDate)
                && $consecutiveEmptyWindows < self::MAX_CONSECUTIVE_EMPTY_WINDOWS
            ) {
                $windowStart = $windowEnd->subDays(self::BACKFILL_WINDOW_DAYS - 1);

                if ($windowStart->lessThan($earliestDate)) {
                    $windowStart = $earliestDate;
                }

                $startStr = $windowStart->format('Y-m-d');
                $endStr = $windowEnd->format('Y-m-d');

                $this->report($onProgress, "Backfilling health window {$startStr} to {$endStr}");

                $windowItems = 0;
                $windowItems += $this->syncDailySummaries($startStr, $endStr);
                $windowItems += $this->syncDailyBodyBattery($startStr, $endStr);
                $windowItems += $this->syncDailySleepSummaries($startStr, $endStr);
                $windowItems += $this->syncDailyHrvSummaries($startStr, $endStr);
                $windowItems += $this->syncDailyVo2Max($startStr, $endStr);
                $windowItems += $this->syncWeightMeasurements($startStr, $endStr);
                $windowItems += $this->syncWeightGoals($startStr, $endStr);
                $windowItems += $this->syncDailyHydration($startStr, $endStr);

                $fitnessAgeStep = $consecutiveEmptyFitnessAgeWindows < 2
                    ? self::BACKFILL_FITNESS_AGE_STEP_DAYS
                    : self::BACKFILL_WINDOW_DAYS;

                $fitnessSynced = $this->syncFitnessAgeRange(
                    $startStr,
                    $endStr,
                    stepDays: $fitnessAgeStep,
                    skipExisting: true,
                    alwaysRefreshDate: $todayStr,
                );
                $windowItems += $fitnessSynced;

                $windowHasFitnessAge = $fitnessSynced > 0 || FitnessAgeRecord::query()
                    ->whereBetween('calendar_date', [$startStr, $endStr])
                    ->whereNotNull('fitness_age')
                    ->exists();

                $consecutiveEmptyFitnessAgeWindows = $windowHasFitnessAge
                    ? 0
                    : $consecutiveEmptyFitnessAgeWindows + 1;

                $itemsSynced += $windowItems;
                $windowHasData = $windowItems > 0 || $windowHasFitnessAge;
                $consecutiveEmptyWindows = $windowHasData ? 0 : $consecutiveEmptyWindows + 1;

                $nextCursor = $windowStart->subDay();

                $syncRun->update([
                    'cursor_date' => $nextCursor->format('Y-m-d'),
                    'items_synced' => $itemsSynced,
                ]);

                $windowEnd = $nextCursor;
                $windowsProcessed++;
            }

            $completed = $windowEnd->lessThan($earliestDate)
                || $consecutiveEmptyWindows >= self::MAX_CONSECUTIVE_EMPTY_WINDOWS;

            if ($completed) {
                $syncRun->update([
                    'status' => 'completed',
                    'finished_at' => CarbonImmutable::now(),
                    'cursor_date' => null,
                    'items_synced' => $itemsSynced,
                ]);
            }

            return [
                'completed' => $completed,
                'consecutiveEmptyWindows' => $consecutiveEmptyWindows,
                'consecutiveEmptyFitnessAgeWindows' => $consecutiveEmptyFitnessAgeWindows,
            ];
        } catch (Throwable $exception) {
            $syncRun->update([
                'status' => 'failed',
                'finished_at' => CarbonImmutable::now(),
                'items_synced' => $itemsSynced,
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            $this->client->onRateLimitWait(null);
        }
    }

    public function syncHeartRateZones(): int
    {
        $zones = $this->callOptional(fn (): ?array => $this->client->heartRateZones());

        if ($zones === null) {
            return 0;
        }

        $synced = 0;

        foreach ($zones as $item) {
            $zone = $this->asAssocArray($item);

            if ($zone === null) {
                continue;
            }

            $sport = $this->asString($zone['sport'] ?? null);

            if ($sport === null) {
                continue;
            }

            HeartRateZone::query()->updateOrCreate(
                ['sport' => $sport],
                [
                    'training_method' => $this->asString($zone['trainingMethod'] ?? null),
                    'max_hr' => $this->asInt($zone['maxHeartRateUsed'] ?? null),
                    'resting_hr' => $this->asInt($zone['restingHeartRateUsed'] ?? null),
                    'lactate_threshold_hr' => $this->asInt($zone['lactateThresholdHeartRateUsed'] ?? null),
                    'zone1_floor' => $this->asInt($zone['zone1Floor'] ?? null),
                    'zone2_floor' => $this->asInt($zone['zone2Floor'] ?? null),
                    'zone3_floor' => $this->asInt($zone['zone3Floor'] ?? null),
                    'zone4_floor' => $this->asInt($zone['zone4Floor'] ?? null),
                    'zone5_floor' => $this->asInt($zone['zone5Floor'] ?? null),
                ],
            );

            $synced++;
        }

        return $synced;
    }

    public function syncDailySummary(string $date): int
    {
        $data = $this->callOptional(fn (): ?array => $this->client->dailySummary($date));

        if ($data === null || ! $this->hasDailySummaryData($data)) {
            return 0;
        }

        $calendarDate = $this->extractDateString($data['calendarDate'] ?? null) ?? $date;

        DailyMetric::query()->updateOrCreate(
            ['calendar_date' => $calendarDate],
            [
                'day_duration_seconds' => $this->positiveMsToSeconds($data['durationInMilliseconds'] ?? null),
                'steps' => $this->asInt($data['totalSteps'] ?? null),
                'step_goal' => $this->asInt($data['dailyStepGoal'] ?? null),
                'distance_meters' => $this->asFloat($data['totalDistanceMeters'] ?? null),
                'total_calories' => $this->asInt($data['totalKilocalories'] ?? null),
                'active_calories' => $this->asInt($data['activeKilocalories'] ?? null),
                'bmr_calories' => $this->asInt($data['bmrKilocalories'] ?? null),
                'resting_hr' => $this->asInt($data['restingHeartRate'] ?? null),
                'seven_day_avg_rhr' => $this->asInt($data['lastSevenDaysAvgRestingHeartRate'] ?? null),
                'min_hr' => $this->asInt($data['minHeartRate'] ?? null),
                'max_hr' => $this->asInt($data['maxHeartRate'] ?? null),
                'avg_stress' => $this->asNonNegativeInt($data['averageStressLevel'] ?? null),
                'max_stress' => $this->asNonNegativeInt($data['maxStressLevel'] ?? null),
                'stress_qualifier' => $this->asString($data['stressQualifier'] ?? null),
                'rest_stress_seconds' => $this->asInt($data['restStressDuration'] ?? null),
                'low_stress_seconds' => $this->asInt($data['lowStressDuration'] ?? null),
                'medium_stress_seconds' => $this->asInt($data['mediumStressDuration'] ?? null),
                'high_stress_seconds' => $this->asInt($data['highStressDuration'] ?? null),
                'body_battery_current' => $this->asInt($data['bodyBatteryMostRecentValue'] ?? null),
                'body_battery_high' => $this->asInt($data['bodyBatteryHighestValue'] ?? null),
                'body_battery_low' => $this->asInt($data['bodyBatteryLowestValue'] ?? null),
                'body_battery_charged' => $this->asInt($data['bodyBatteryChargedValue'] ?? null),
                'body_battery_drained' => $this->asInt($data['bodyBatteryDrainedValue'] ?? null),
                'body_battery_sleep' => $this->asInt($data['bodyBatteryDuringSleep'] ?? null),
                'moderate_intensity_minutes' => $this->asInt($data['moderateIntensityMinutes'] ?? null),
                'vigorous_intensity_minutes' => $this->asInt($data['vigorousIntensityMinutes'] ?? null),
                'intensity_minutes_goal' => $this->asInt($data['intensityMinutesGoal'] ?? null),
                'floors_ascended' => $this->asFloat($data['floorsAscended'] ?? null),
                'floors_descended' => $this->asFloat($data['floorsDescended'] ?? null),
                'floors_goal' => $this->asInt($data['userFloorsAscendedGoal'] ?? null),
                'avg_respiration' => $this->asFloat($data['avgWakingRespirationValue'] ?? null),
                'min_respiration' => $this->asFloat($data['lowestRespirationValue'] ?? null),
                'max_respiration' => $this->asFloat($data['highestRespirationValue'] ?? null),
                'avg_spo2' => $this->asFloat($data['averageSpo2'] ?? null),
                'lowest_spo2' => $this->asFloat($data['lowestSpo2'] ?? null),
                'latest_spo2' => $this->asFloat($data['latestSpo2'] ?? null),
                'raw_payload' => $data,
            ],
        );

        return 1;
    }

    /**
     * Sync a multi-day range of daily summaries in a single v2 API request.
     */
    public function syncDailySummaries(string $startDate, string $endDate): int
    {
        $response = $this->callOptional(fn (): ?array => $this->client->dailySummaries($startDate, $endDate));

        if ($response === null) {
            return 0;
        }

        $entries = $this->asArray($response['data'] ?? null);

        if ($entries === null) {
            return 0;
        }

        $synced = 0;

        foreach ($entries as $item) {
            $entry = $this->asAssocArray($item);

            if ($entry === null) {
                continue;
            }

            $calendarDate = $this->extractDateString($entry['calendarDate'] ?? null);

            if ($calendarDate === null) {
                continue;
            }

            $chronology = $this->asAssocArray($entry['wellnessChronology'] ?? null);
            $movement = $this->asAssocArray($entry['movement'] ?? null);
            $steps = $this->asAssocArray($movement['steps'] ?? null);
            $floorsAscended = $this->asAssocArray($movement['floorsAscended'] ?? null);
            $floorsDescended = $this->asAssocArray($movement['floorsDescended'] ?? null);
            $calories = $this->asAssocArray($entry['calories'] ?? null);
            $heartRate = $this->asAssocArray($entry['heartRate'] ?? null);
            $intensity = $this->asAssocArray($entry['intensityMinutes'] ?? null);
            $stress = $this->asAssocArray($entry['stress'] ?? null);
            $bodyBattery = $this->asAssocArray($entry['bodyBattery'] ?? null);
            $respiration = $this->asAssocArray($entry['respiration'] ?? null);
            $pulseOx = $this->asAssocArray($entry['pulseOx'] ?? null);

            $attributes = [
                'day_duration_seconds' => $this->positiveMsToSeconds($chronology['totalDurationInMillis'] ?? null),
                'steps' => $this->asInt($steps['value'] ?? null),
                'step_goal' => $this->asInt($steps['goal'] ?? null),
                'distance_meters' => $this->asFloat($steps['distanceInMeters'] ?? null),
                'total_calories' => $this->asInt($calories['burnedTotal'] ?? null),
                'active_calories' => $this->asInt($calories['burnedActive'] ?? null),
                'bmr_calories' => $this->asInt($calories['burnedResting'] ?? null),
                'resting_hr' => $this->asInt($heartRate['restingValue'] ?? null),
                'min_hr' => $this->asInt($heartRate['minValue'] ?? null),
                'max_hr' => $this->asInt($heartRate['maxValue'] ?? null),
                'avg_stress' => $this->asNonNegativeInt($stress['avgLevel'] ?? null),
                'max_stress' => $this->asNonNegativeInt($stress['maxLevel'] ?? null),
                'stress_qualifier' => $this->asString($stress['qualifier'] ?? null),
                'rest_stress_seconds' => $this->msToSeconds($stress['restDurationInMillis'] ?? null),
                'low_stress_seconds' => $this->msToSeconds($stress['lowStressDurationInMillis'] ?? null),
                'medium_stress_seconds' => $this->msToSeconds($stress['mediumStressDurationInMillis'] ?? null),
                'high_stress_seconds' => $this->msToSeconds($stress['highStressDurationInMillis'] ?? null),
                'body_battery_current' => $this->asInt($bodyBattery['latestValue'] ?? null),
                'body_battery_high' => $this->asInt($bodyBattery['maxValue'] ?? null),
                'body_battery_low' => $this->asInt($bodyBattery['minValue'] ?? null),
                'body_battery_charged' => $this->asInt($bodyBattery['chargedValue'] ?? null),
                'body_battery_drained' => $this->asInt($bodyBattery['drainedValue'] ?? null),
                'body_battery_sleep' => $this->extractBodyBatterySleepImpact($bodyBattery),
                'moderate_intensity_minutes' => $this->asInt($intensity['moderate'] ?? null),
                'vigorous_intensity_minutes' => $this->asInt($intensity['vigorous'] ?? null),
                'intensity_minutes_goal' => $this->asInt($intensity['goal'] ?? null),
                'floors_ascended' => $this->asFloat($floorsAscended['value'] ?? null),
                'floors_descended' => $this->asFloat($floorsDescended['value'] ?? null),
                'floors_goal' => $this->asInt($floorsAscended['goal'] ?? null),
                'avg_respiration' => $this->asFloat($respiration['avgValue'] ?? null),
                'min_respiration' => $this->asFloat($respiration['minValue'] ?? null),
                'max_respiration' => $this->asFloat($respiration['maxValue'] ?? null),
                'avg_spo2' => $this->asFloat($pulseOx['avgValue'] ?? null),
                'lowest_spo2' => $this->asFloat($pulseOx['minValue'] ?? null),
                'latest_spo2' => $this->asFloat($pulseOx['latestValue'] ?? null),
                'raw_payload' => $entry,
            ];

            if (! $this->hasDailyMetricValues($attributes)) {
                continue;
            }

            DailyMetric::query()->updateOrCreate(
                ['calendar_date' => $calendarDate],
                $attributes,
            );

            $synced++;
        }

        return $synced;
    }

    public function syncDailyHeartRate(string $date): int
    {
        $data = $this->callOptional(fn (): ?array => $this->client->dailyHeartRate($date));

        if ($data === null) {
            return 0;
        }

        $rawValues = $this->asArray($data['heartRateValues'] ?? null);

        if ($rawValues === null) {
            return 0;
        }

        $series = $this->normalizeIntradaySeries($rawValues, minValue: 1);

        if ($series === []) {
            return 0;
        }

        $calendarDate = $this->extractDateString($data['calendarDate'] ?? null) ?? $date;

        DailyMetric::query()->updateOrCreate(
            ['calendar_date' => $calendarDate],
            ['heart_rate_series' => $series],
        );

        return 1;
    }

    public function syncDailyBodyBattery(string $startDate, string $endDate): int
    {
        $entries = $this->callOptional(fn (): ?array => $this->client->dailyBodyBattery($startDate, $endDate));

        if ($entries === null) {
            return 0;
        }

        $synced = 0;

        foreach ($entries as $item) {
            $entry = $this->asAssocArray($item);

            if ($entry === null) {
                continue;
            }

            $calendarDate = $this->extractDateString($entry['date'] ?? $entry['calendarDate'] ?? null);

            if ($calendarDate === null) {
                continue;
            }

            $rawValues = $this->asArray($entry['bodyBatteryValuesArray'] ?? null);
            $series = $rawValues !== null ? $this->normalizeIntradaySeries($rawValues, minValue: 0) : [];
            $charged = $this->asInt($entry['charged'] ?? null);
            $drained = $this->asInt($entry['drained'] ?? null);

            if ($series === [] && $charged === null && $drained === null) {
                continue;
            }

            $existing = DailyMetric::query()->where('calendar_date', $calendarDate)->first();
            $attributes = [];

            if ($series !== []) {
                $attributes['body_battery_series'] = $series;
            }

            if ($charged !== null && ($existing === null || $existing->getAttribute('body_battery_charged') === null)) {
                $attributes['body_battery_charged'] = $charged;
            }

            if ($drained !== null && ($existing === null || $existing->getAttribute('body_battery_drained') === null)) {
                $attributes['body_battery_drained'] = $drained;
            }

            if ($attributes === []) {
                continue;
            }

            DailyMetric::query()->updateOrCreate(
                ['calendar_date' => $calendarDate],
                $attributes,
            );

            $synced++;
        }

        return $synced;
    }

    public function syncDailyHydration(string $startDate, string $endDate): int
    {
        $entries = $this->callOptional(fn (): ?array => $this->client->dailyHydration($startDate, $endDate));

        if ($entries === null) {
            return 0;
        }

        $synced = 0;

        foreach ($entries as $item) {
            $entry = $this->asAssocArray($item);

            if ($entry === null) {
                continue;
            }

            $calendarDate = $this->extractDateString($entry['calendarDate'] ?? null);
            $hydrationMl = $this->asInt($entry['valueInML'] ?? null);

            if ($calendarDate === null || $hydrationMl === null || $hydrationMl <= 0) {
                continue;
            }

            DailyMetric::query()->updateOrCreate(
                ['calendar_date' => $calendarDate],
                ['hydration_ml' => $hydrationMl],
            );

            $synced++;
        }

        return $synced;
    }

    public function syncDailySleepSummaries(string $startDate, string $endDate): int
    {
        $entries = $this->callOptional(fn (): ?array => $this->client->dailySleepSummaries($startDate, $endDate));

        if ($entries === null) {
            return 0;
        }

        $synced = 0;

        foreach ($entries as $item) {
            $entry = $this->asAssocArray($item);

            if ($entry === null) {
                continue;
            }

            if ($this->upsertSleepPayload($entry, fallbackDate: null)) {
                $synced++;
            }
        }

        return $synced;
    }

    public function syncDailySleep(string $date): int
    {
        $payload = $this->callOptional(fn (): ?array => $this->client->dailySleep($date));

        if ($payload === null) {
            return 0;
        }

        return $this->upsertSleepPayload($payload, fallbackDate: $date) ? 1 : 0;
    }

    public function syncDailyHrvSummaries(string $startDate, string $endDate): int
    {
        $response = $this->callOptional(fn (): ?array => $this->client->dailyHrvSummaries($startDate, $endDate));

        if ($response === null) {
            return 0;
        }

        $items = isset($response['hrvSummaries']) && is_array($response['hrvSummaries'])
            ? $response['hrvSummaries']
            : $response;

        $synced = 0;

        foreach ($items as $item) {
            $entry = $this->asAssocArray($item);

            if ($entry === null) {
                continue;
            }

            $calendarDate = $this->extractDateString($entry['calendarDate'] ?? null);

            if ($calendarDate === null) {
                continue;
            }

            $baseline = $this->asAssocArray($entry['baseline'] ?? null);
            $weeklyAvg = $this->asInt($entry['weeklyAvg'] ?? null);
            $lastNightAvg = $this->asInt($entry['lastNightAvg'] ?? null);
            $lastNight5MinHigh = $this->asInt($entry['lastNight5MinHigh'] ?? null);
            $status = $this->asString($entry['status'] ?? null);

            if ($weeklyAvg === null && $lastNightAvg === null && $lastNight5MinHigh === null && $status === null) {
                continue;
            }

            HrvRecord::query()->updateOrCreate(
                ['calendar_date' => $calendarDate],
                [
                    'weekly_avg' => $weeklyAvg,
                    'last_night_avg' => $lastNightAvg,
                    'last_night_5min_high' => $lastNight5MinHigh,
                    'status' => $status,
                    'baseline_low_upper' => $this->asInt($baseline['lowUpper'] ?? null),
                    'baseline_balanced_low' => $this->asInt($baseline['balancedLow'] ?? null),
                    'baseline_balanced_upper' => $this->asInt($baseline['balancedUpper'] ?? null),
                    'feedback_phrase' => $this->asString($entry['feedbackPhrase'] ?? null),
                ],
            );

            $synced++;
        }

        return $synced;
    }

    public function syncDailyVo2Max(string $startDate, string $endDate): int
    {
        $entries = $this->callOptional(fn (): ?array => $this->client->dailyVo2Max($startDate, $endDate));

        if ($entries === null) {
            return 0;
        }

        $synced = 0;
        $sportKeys = [
            'generic' => 'running',
            'cycling' => 'cycling',
        ];

        foreach ($entries as $item) {
            $row = $this->asAssocArray($item);

            if ($row === null) {
                continue;
            }

            foreach ($sportKeys as $payloadKey => $sport) {
                $entry = $this->asAssocArray($row[$payloadKey] ?? null);

                if ($entry === null) {
                    continue;
                }

                $calendarDate = $this->extractDateString($entry['calendarDate'] ?? null);
                $precise = $this->asFloat($entry['vo2MaxPreciseValue'] ?? null);
                $value = $this->asFloat($entry['vo2MaxValue'] ?? null);

                if ($calendarDate === null || ($precise === null && $value === null)) {
                    continue;
                }

                Vo2MaxRecord::query()->updateOrCreate(
                    [
                        'calendar_date' => $calendarDate,
                        'sport' => $sport,
                    ],
                    [
                        'vo2_max_precise' => $precise,
                        'vo2_max' => $value,
                        'sub_sport' => $this->asString($entry['subSport'] ?? null),
                    ],
                );

                $synced++;
            }
        }

        return $synced;
    }

    public function syncFitnessAgeRange(
        string $startDate,
        string $endDate,
        int $stepDays = 1,
        bool $skipExisting = true,
        ?string $alwaysRefreshDate = null,
    ): int {
        $step = max(1, $stepDays);
        $start = CarbonImmutable::parse($startDate)->startOfDay();
        $cursor = CarbonImmutable::parse($endDate)->startOfDay();

        if ($cursor->lessThan($start)) {
            return 0;
        }

        /** @var list<string> $candidateDates */
        $candidateDates = [];

        while ($cursor->greaterThanOrEqualTo($start)) {
            $candidateDates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->subDays($step);
        }

        $existingDates = [];

        if ($skipExisting) {
            $existingDates = array_fill_keys(
                FitnessAgeRecord::query()
                    ->whereIn('calendar_date', $candidateDates)
                    ->whereNotNull('fitness_age')
                    ->pluck('calendar_date')
                    ->all(),
                true,
            );
        }

        $synced = 0;

        foreach ($candidateDates as $dateStr) {
            if ($skipExisting && isset($existingDates[$dateStr]) && $dateStr !== $alwaysRefreshDate) {
                continue;
            }

            $synced += $this->syncFitnessAge($dateStr);
        }

        return $synced;
    }

    public function syncFitnessAge(string $date): int
    {
        $data = $this->callOptional(fn (): ?array => $this->client->fitnessAge($date));

        if ($data === null) {
            return 0;
        }

        $chronologicalAge = $this->asFloat($data['chronologicalAge'] ?? null);
        $fitnessAge = $this->asFloat($data['fitnessAge'] ?? null);
        $previousFitnessAge = $this->asFloat($data['previousFitnessAge'] ?? null);

        if ($chronologicalAge === null && $fitnessAge === null) {
            return 0;
        }

        FitnessAgeRecord::query()->updateOrCreate(
            ['calendar_date' => $date],
            [
                'chronological_age' => $chronologicalAge,
                'fitness_age' => $fitnessAge,
                'previous_fitness_age' => $previousFitnessAge,
                'components' => $this->asAssocArray($data['components'] ?? null),
            ],
        );

        return $fitnessAge !== null ? 1 : 0;
    }

    public function syncWeightMeasurements(string $startDate, string $endDate): int
    {
        $response = $this->callOptional(
            fn (): ?array => $this->client->weightMeasurements($startDate, $endDate, includeAll: true),
        );

        if ($response === null) {
            return 0;
        }

        $summaries = $this->asArray($response['dailyWeightSummaries'] ?? null);

        if ($summaries === null) {
            return 0;
        }

        $synced = 0;

        foreach ($summaries as $summaryItem) {
            $summary = $this->asAssocArray($summaryItem);

            if ($summary === null) {
                continue;
            }

            $summaryDate = $this->extractDateString($summary['summaryDate'] ?? null);
            $metrics = $this->asArray($summary['allWeightMetrics'] ?? null);

            if ($metrics === null || $metrics === []) {
                $latest = $this->asAssocArray($summary['latestWeight'] ?? null);
                $metrics = $latest !== null ? [$latest] : [];
            }

            foreach ($metrics as $metricItem) {
                $metric = $this->asAssocArray($metricItem);

                if ($metric === null) {
                    continue;
                }

                $samplePk = $this->asInt($metric['samplePk'] ?? null);
                $weightGrams = $this->asInt($metric['weight'] ?? null);

                if ($samplePk === null || $samplePk <= 0 || $weightGrams === null || $weightGrams <= 0) {
                    continue;
                }

                $measuredAt = $this->timestampMsToUtc($metric['timestampGMT'] ?? null)
                    ?? $this->timestampMsToUtc($metric['date'] ?? null);

                $calendarDate = $this->extractDateString($metric['calendarDate'] ?? null)
                    ?? $summaryDate
                    ?? $measuredAt?->setTimezone($this->timezone())->format('Y-m-d');

                if ($calendarDate === null) {
                    continue;
                }

                WeightMeasurement::query()->updateOrCreate(
                    ['garmin_sample_pk' => $samplePk],
                    [
                        'calendar_date' => $calendarDate,
                        'measured_at' => $measuredAt,
                        'weight_grams' => $weightGrams,
                        'bmi' => $this->asFloat($metric['bmi'] ?? null),
                        'body_fat_pct' => $this->asFloat($metric['bodyFat'] ?? null),
                        'body_water_pct' => $this->asFloat($metric['bodyWater'] ?? null),
                        'bone_mass_grams' => $this->asInt($metric['boneMass'] ?? null),
                        'muscle_mass_grams' => $this->asInt($metric['muscleMass'] ?? null),
                        'source_type' => $this->asString($metric['sourceType'] ?? null),
                    ],
                );

                $synced++;
            }
        }

        return $synced;
    }

    public function syncWeightGoals(string $startDate, string $endDate): int
    {
        $response = $this->callOptional(
            fn (): ?array => $this->client->effectiveWeightGoals($startDate, $endDate),
        );

        if ($response === null) {
            return 0;
        }

        $goals = isset($response['userGoals']) && is_array($response['userGoals'])
            ? $response['userGoals']
            : $response;

        $synced = 0;

        foreach ($goals as $item) {
            $goal = $this->asAssocArray($item);

            if ($goal === null) {
                continue;
            }

            $goalType = strtoupper((string) ($goal['userGoalType'] ?? ''));

            if ($goalType !== '' && $goalType !== 'WEIGHT_GRAMS') {
                continue;
            }

            $goalStartDate = $this->extractDateString($goal['startDate'] ?? null);
            $goalGrams = $this->asInt($goal['goalValue'] ?? null);

            if ($goalStartDate === null || $goalGrams === null || $goalGrams <= 0) {
                continue;
            }

            WeightGoal::query()->updateOrCreate(
                ['start_date' => $goalStartDate],
                [
                    'end_date' => $this->extractDateString($goal['endDate'] ?? null),
                    'goal_grams' => $goalGrams,
                    'source_category' => $this->asString($goal['userGoalCategory'] ?? null),
                    'garmin_updated_at' => $this->parseGarminDatetime($goal['updateDate'] ?? null, 'UTC'),
                ],
            );

            $synced++;
        }

        return $synced;
    }

    /**
     * Upsert a single Garmin activity summary and seed its split-based Best Efforts.
     *
     * @param  array<string, mixed>  $raw
     */
    public function upsertActivity(array $raw): ?Activity
    {
        $garminActivityId = $this->asInt($raw['activityId'] ?? null);

        if ($garminActivityId === null || $garminActivityId <= 0) {
            return null;
        }

        $activityType = $this->asAssocArray($raw['activityType'] ?? null);
        $typeKey = $this->asString($activityType['typeKey'] ?? null) ?? 'other';
        $classification = $this->classifier->classify($typeKey);

        [$startedAtLocal, $startedAtUtc] = $this->resolveActivityStartTimes($raw);

        if ($startedAtLocal === null || $startedAtUtc === null) {
            return null;
        }

        $isBiking = $classification['sport_category'] === 'biking';

        $avgCadence = $isBiking
            ? ($this->asFloat($raw['averageBikingCadenceInRevPerMinute'] ?? null)
                ?? $this->asFloat($raw['averageRunningCadenceInStepsPerMinute'] ?? null))
            : ($this->asFloat($raw['averageRunningCadenceInStepsPerMinute'] ?? null)
                ?? $this->asFloat($raw['averageBikingCadenceInRevPerMinute'] ?? null));

        $maxCadence = $isBiking
            ? ($this->asFloat($raw['maxBikingCadenceInRevPerMinute'] ?? null)
                ?? $this->asFloat($raw['maxRunningCadenceInStepsPerMinute'] ?? null))
            : ($this->asFloat($raw['maxRunningCadenceInStepsPerMinute'] ?? null)
                ?? $this->asFloat($raw['maxBikingCadenceInRevPerMinute'] ?? null));

        $existing = Activity::query()
            ->where('garmin_activity_id', $garminActivityId)
            ->first();

        $runDurationSeconds = null;
        $walkDurationSeconds = null;
        $idleDurationSeconds = null;

        if (isset($raw['splitSummaries']) && is_array($raw['splitSummaries'])) {
            foreach ($raw['splitSummaries'] as $split) {
                if (! is_array($split)) {
                    continue;
                }

                $splitType = $this->asString($split['splitType'] ?? null);
                $splitDuration = $this->asFloat($split['duration'] ?? null);

                if ($splitType === null || $splitDuration === null) {
                    continue;
                }

                if ($splitType === 'RWD_RUN') {
                    $runDurationSeconds = round($splitDuration, 2);
                } elseif ($splitType === 'RWD_WALK') {
                    $walkDurationSeconds = round($splitDuration, 2);
                } elseif ($splitType === 'RWD_STAND') {
                    $idleDurationSeconds = round($splitDuration, 2);
                }
            }
        }

        $activity = Activity::query()->updateOrCreate(
            ['garmin_activity_id' => $garminActivityId],
            [
                'name' => $this->asString($raw['activityName'] ?? null) ?? 'Untitled Activity',
                'activity_type_key' => $typeKey,
                'sport_category' => $classification['sport_category'],
                'sport_discipline' => $classification['sport_discipline'],
                'is_indoor' => $classification['is_indoor'],
                'started_at_local' => $startedAtLocal,
                'started_at_utc' => $startedAtUtc,
                'location_name' => $this->asString($raw['locationName'] ?? null),
                'start_lat' => $this->asFloat($raw['startLatitude'] ?? null) ?? $existing?->start_lat,
                'start_lng' => $this->asFloat($raw['startLongitude'] ?? null) ?? $existing?->start_lng,
                'distance_meters' => $this->asFloat($raw['distance'] ?? null) ?? 0.0,
                'duration_seconds' => $this->asFloat($raw['duration'] ?? null) ?? 0.0,
                'moving_duration_seconds' => $this->asFloat($raw['movingDuration'] ?? null),
                'elapsed_duration_seconds' => $this->asFloat($raw['elapsedDuration'] ?? null),
                'elevation_gain' => $this->asFloat($raw['elevationGain'] ?? null),
                'elevation_loss' => $this->asFloat($raw['elevationLoss'] ?? null),
                'min_elevation' => $this->asFloat($raw['minElevation'] ?? null),
                'max_elevation' => $this->asFloat($raw['maxElevation'] ?? null),
                'avg_speed' => $this->asFloat($raw['averageSpeed'] ?? null),
                'max_speed' => $this->asFloat($raw['maxSpeed'] ?? null),
                'avg_grade_adjusted_speed' => $this->asFloat($raw['avgGradeAdjustedSpeed'] ?? null) ?? $existing?->avg_grade_adjusted_speed,
                'calories' => $this->asInt($raw['calories'] ?? null),
                'bmr_calories' => $this->asInt($raw['bmrCalories'] ?? null) ?? $existing?->bmr_calories,
                'sweat_loss_ml' => $this->asInt($raw['waterEstimated'] ?? null) ?? $existing?->sweat_loss_ml,
                'avg_hr' => $this->asInt($raw['averageHR'] ?? null),
                'max_hr' => $this->asInt($raw['maxHR'] ?? null),
                'avg_cadence' => $avgCadence,
                'max_cadence' => $maxCadence,
                'avg_stride_length' => $this->asFloat($raw['avgStrideLength'] ?? null),
                'avg_vertical_oscillation' => $this->asFloat($raw['avgVerticalOscillation'] ?? null) ?? $existing?->avg_vertical_oscillation,
                'avg_vertical_ratio' => $this->asFloat($raw['avgVerticalRatio'] ?? null) ?? $existing?->avg_vertical_ratio,
                'avg_ground_contact_time' => $this->asFloat($raw['avgGroundContactTime'] ?? null) ?? $existing?->avg_ground_contact_time,
                'steps' => $this->asInt($raw['steps'] ?? null),
                'aerobic_te' => $this->asFloat($raw['aerobicTrainingEffect'] ?? null),
                'anaerobic_te' => $this->asFloat($raw['anaerobicTrainingEffect'] ?? null),
                'training_load' => $this->asFloat($raw['activityTrainingLoad'] ?? null),
                'training_effect_label' => $this->asString($raw['trainingEffectLabel'] ?? null),
                'aerobic_te_message' => $this->asString($raw['aerobicTrainingEffectMessage'] ?? null) ?? $existing?->aerobic_te_message,
                'anaerobic_te_message' => $this->asString($raw['anaerobicTrainingEffectMessage'] ?? null) ?? $existing?->anaerobic_te_message,
                'body_battery_diff' => $this->asInt($raw['differenceBodyBattery'] ?? null),
                'hr_zone_1_seconds' => $this->asFloat($raw['hrTimeInZone_1'] ?? null),
                'hr_zone_2_seconds' => $this->asFloat($raw['hrTimeInZone_2'] ?? null),
                'hr_zone_3_seconds' => $this->asFloat($raw['hrTimeInZone_3'] ?? null),
                'hr_zone_4_seconds' => $this->asFloat($raw['hrTimeInZone_4'] ?? null),
                'hr_zone_5_seconds' => $this->asFloat($raw['hrTimeInZone_5'] ?? null),
                'avg_power' => $this->asFloat($raw['avgPower'] ?? null),
                'max_power' => $this->asFloat($raw['maxPower'] ?? null),
                'normalized_power' => $this->asFloat($raw['normPower'] ?? $raw['normalizedPower'] ?? null),
                'ftp' => $this->asFloat($raw['functionalThresholdPower'] ?? null),
                'tss' => $this->asFloat($raw['trainingStressScore'] ?? null),
                'intensity_factor' => $this->asFloat($raw['intensityFactor'] ?? null),
                'total_work' => $this->asFloat($raw['totalWork'] ?? null) ?? $existing?->total_work,
                'left_balance' => $this->asFloat($raw['avgLeftBalance'] ?? $raw['leftBalance'] ?? null),
                'max_20min_power' => $this->asFloat($raw['max20MinPower'] ?? null),
                'power_zone_1_seconds' => $this->asFloat($raw['powerTimeInZone_1'] ?? null),
                'power_zone_2_seconds' => $this->asFloat($raw['powerTimeInZone_2'] ?? null),
                'power_zone_3_seconds' => $this->asFloat($raw['powerTimeInZone_3'] ?? null),
                'power_zone_4_seconds' => $this->asFloat($raw['powerTimeInZone_4'] ?? null),
                'power_zone_5_seconds' => $this->asFloat($raw['powerTimeInZone_5'] ?? null),
                'power_zone_6_seconds' => $this->asFloat($raw['powerTimeInZone_6'] ?? null),
                'power_zone_7_seconds' => $this->asFloat($raw['powerTimeInZone_7'] ?? null),
                'power_curve' => $this->extractPowerCurve($raw),
                'grit' => $this->asFloat($raw['grit'] ?? null),
                'avg_flow' => $this->asFloat($raw['avgFlow'] ?? null),
                'jump_count' => $this->asInt($raw['jumpCount'] ?? null),
                'unpaved_pct' => $this->extractUnpavedPercentage($raw),
                'workout_feel' => $this->asInt($raw['workoutFeel'] ?? null) ?? $existing?->workout_feel,
                'workout_rpe' => $this->asInt($raw['workoutRpe'] ?? null) ?? $existing?->workout_rpe,
                'begin_stamina' => $this->asInt($raw['beginPotentialStamina'] ?? null) ?? $existing?->begin_stamina,
                'end_stamina' => $this->asInt($raw['endPotentialStamina'] ?? null) ?? $existing?->end_stamina,
                'min_stamina' => $this->asInt($raw['minActivityStamina'] ?? null) ?? $existing?->min_stamina,
                'avg_respiration_rate' => $this->asFloat($raw['avgRespirationRate'] ?? null) ?? $existing?->avg_respiration_rate,
                'min_respiration_rate' => $this->asFloat($raw['minRespirationRate'] ?? null) ?? $existing?->min_respiration_rate,
                'max_respiration_rate' => $this->asFloat($raw['maxRespirationRate'] ?? null) ?? $existing?->max_respiration_rate,
                'min_temperature' => $this->asFloat($raw['minTemperature'] ?? null) ?? $existing?->min_temperature,
                'max_temperature' => $this->asFloat($raw['maxTemperature'] ?? null) ?? $existing?->max_temperature,
                'moderate_intensity_minutes' => $this->asInt($raw['moderateIntensityMinutes'] ?? null) ?? $existing?->moderate_intensity_minutes,
                'vigorous_intensity_minutes' => $this->asInt($raw['vigorousIntensityMinutes'] ?? null) ?? $existing?->vigorous_intensity_minutes,
                'run_duration_seconds' => $runDurationSeconds ?? $existing?->run_duration_seconds,
                'walk_duration_seconds' => $walkDurationSeconds ?? $existing?->walk_duration_seconds,
                'idle_duration_seconds' => $idleDurationSeconds ?? $existing?->idle_duration_seconds,
                'is_favorite' => (bool) ($raw['isFavorite'] ?? $raw['favorite'] ?? false),
                'is_pr' => (bool) ($raw['isPR'] ?? $raw['pr'] ?? false),
                'is_manual' => (bool) ($raw['isManualActivity'] ?? $raw['manualActivity'] ?? false),
                'raw_summary' => $raw,
            ],
        );

        $this->seedBestEffortsFromSplits(
            activity: $activity,
            raw: $raw,
            sportCategory: $classification['sport_category'],
            sportDiscipline: $classification['sport_discipline'],
            isIndoor: $classification['is_indoor'],
            startedAtLocal: $startedAtLocal,
        );

        $this->gearService->autoAssignDefaultGear($activity);

        if (
            $existing === null
            && ! $activity->is_manual
            && (bool) config('services.garmin.auto_sync_streams', true)
        ) {
            SyncActivityStreamJob::dispatch($activity->id);
        }

        if (
            $existing === null
            && ! $activity->is_indoor
            && $activity->start_lat !== null
            && $activity->start_lng !== null
            && (bool) config('services.open_meteo.enabled', true)
            && (bool) config('services.open_meteo.auto_sync', true)
        ) {
            SyncActivityWeatherJob::dispatch($activity->id);
        }

        return $activity;
    }

    private function syncRecentActivities(): int
    {
        $latestStartedAtUtc = Activity::query()->max('started_at_utc');
        $cutoffUtc = is_string($latestStartedAtUtc) && $latestStartedAtUtc !== ''
            ? CarbonImmutable::parse($latestStartedAtUtc, 'UTC')->subDay()
            : null;

        $synced = 0;
        $offset = 0;

        while (true) {
            $batch = $this->callOptional(
                fn (): ?array => $this->client->searchActivities([
                    'start' => $offset,
                    'limit' => self::INCREMENTAL_ACTIVITY_PAGE_SIZE,
                ]),
            );

            if ($batch === null || $batch === []) {
                break;
            }

            $reachedCutoff = false;

            foreach ($batch as $item) {
                $raw = $this->asAssocArray($item);

                if ($raw === null) {
                    continue;
                }

                $activity = $this->upsertActivity($raw);

                if ($activity === null) {
                    continue;
                }

                $synced++;

                if ($cutoffUtc !== null) {
                    [, $startedAtUtc] = $this->resolveActivityStartTimes($raw);

                    if ($startedAtUtc !== null && $startedAtUtc->lessThan($cutoffUtc)) {
                        $reachedCutoff = true;
                    }
                }
            }

            $batchCount = count($batch);
            $offset += $batchCount;

            if ($batchCount < self::INCREMENTAL_ACTIVITY_PAGE_SIZE) {
                break;
            }

            if ($cutoffUtc !== null && $reachedCutoff) {
                break;
            }

            if ($cutoffUtc === null && $offset >= self::INITIAL_ACTIVITY_LIMIT) {
                break;
            }
        }

        return $synced;
    }

    /**
     * @param  (callable(string): void)|null  $onProgress
     */
    private function syncAllActivities(?callable $onProgress = null): int
    {
        $synced = 0;
        $offset = 0;

        while (true) {
            $batch = $this->callOptional(
                fn (): ?array => $this->client->searchActivities([
                    'start' => $offset,
                    'limit' => self::BACKFILL_ACTIVITY_PAGE_SIZE,
                ]),
            );

            if ($batch === null || $batch === []) {
                break;
            }

            foreach ($batch as $item) {
                $raw = $this->asAssocArray($item);

                if ($raw === null) {
                    continue;
                }

                if ($this->upsertActivity($raw) !== null) {
                    $synced++;
                }
            }

            $batchCount = count($batch);
            $offset += $batchCount;

            $this->report($onProgress, "Synced {$synced} activities so far");

            if ($batchCount < self::BACKFILL_ACTIVITY_PAGE_SIZE) {
                break;
            }
        }

        return $synced;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertSleepPayload(array $payload, ?string $fallbackDate): bool
    {
        $dto = $this->asAssocArray($payload['dailySleepDTO'] ?? null) ?? $payload;

        $calendarDate = $this->extractDateString($dto['calendarDate'] ?? $payload['calendarDate'] ?? null)
            ?? $fallbackDate;

        if ($calendarDate === null) {
            return false;
        }

        $sleepScores = $this->asAssocArray($dto['sleepScores'] ?? $payload['sleepScores'] ?? null);
        $overallScore = $this->asAssocArray($sleepScores['overall'] ?? null);
        $sleepNeed = $this->asAssocArray($dto['sleepNeed'] ?? $payload['sleepNeed'] ?? null);
        $nextSleepNeed = $this->asAssocArray($dto['nextSleepNeed'] ?? $payload['nextSleepNeed'] ?? null);

        $sleepSeconds = $this->asInt($dto['sleepTimeSeconds'] ?? null);

        $candidateUpdates = [
            'sleep_seconds' => $sleepSeconds,
            'deep_seconds' => $this->asInt($dto['deepSleepSeconds'] ?? null),
            'light_seconds' => $this->asInt($dto['lightSleepSeconds'] ?? null),
            'rem_seconds' => $this->asInt($dto['remSleepSeconds'] ?? null),
            'awake_seconds' => $this->asInt($dto['awakeSleepSeconds'] ?? null),
            'nap_seconds' => $this->asInt($dto['napTimeSeconds'] ?? null),
            'sleep_start_local' => $this->timestampMsToLocal($dto['sleepStartTimestampLocal'] ?? null),
            'sleep_end_local' => $this->timestampMsToLocal($dto['sleepEndTimestampLocal'] ?? null),
            'sleep_score' => $this->asInt($overallScore['value'] ?? null),
            'sleep_need_minutes' => $this->asInt($sleepNeed['actual'] ?? null),
            'next_sleep_need_minutes' => $this->asInt($nextSleepNeed['actual'] ?? null),
        ];

        $updates = array_filter(
            $candidateUpdates,
            static fn (mixed $value): bool => $value !== null,
        );

        if ($updates === []) {
            return false;
        }

        $existing = SleepRecord::query()->where('calendar_date', $calendarDate)->first();

        if ($existing === null || $sleepSeconds !== null) {
            $updates['raw_payload'] = $payload;
        }

        SleepRecord::query()->updateOrCreate(
            ['calendar_date' => $calendarDate],
            $updates,
        );

        return true;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function seedBestEffortsFromSplits(
        Activity $activity,
        array $raw,
        string $sportCategory,
        ?string $sportDiscipline,
        bool $isIndoor,
        CarbonImmutable $startedAtLocal,
    ): void {
        $effectiveDiscipline = $this->asString($activity->getAttribute('discipline_override')) ?? $sportDiscipline;

        $sport = null;
        $splitMap = [];

        if ($sportCategory === 'running' && ! $isIndoor) {
            $sport = 'running';
            $splitMap = self::RUNNING_SPLIT_MAP;
        } elseif ($sportCategory === 'biking' && $effectiveDiscipline === 'road') {
            $sport = 'road_biking';
            $splitMap = self::ROAD_BIKING_SPLIT_MAP;
        }

        if ($sport === null) {
            return;
        }

        $activityId = $activity->getKey();

        foreach ($splitMap as $payloadKey => $meta) {
            $elapsedSeconds = $this->asFloat($raw[$payloadKey] ?? null);

            if ($elapsedSeconds === null || $elapsedSeconds <= 0.0) {
                continue;
            }

            $existing = BestEffort::query()
                ->where('activity_id', $activityId)
                ->where('sport', $sport)
                ->where('distance_key', $meta['distance_key'])
                ->first();

            if ($existing !== null) {
                if ($existing->getAttribute('source') === 'fit_stream') {
                    continue;
                }

                $existing->update([
                    'distance_meters' => $meta['distance_meters'],
                    'elapsed_seconds' => $elapsedSeconds,
                    'achieved_at' => $startedAtLocal,
                    'source' => 'garmin_split',
                ]);

                continue;
            }

            BestEffort::query()->create([
                'activity_id' => $activityId,
                'sport' => $sport,
                'distance_key' => $meta['distance_key'],
                'distance_meters' => $meta['distance_meters'],
                'elapsed_seconds' => $elapsedSeconds,
                'achieved_at' => $startedAtLocal,
                'source' => 'garmin_split',
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

    /**
     * @param  array<string, mixed>|null  $bodyBattery
     */
    private function extractBodyBatterySleepImpact(?array $bodyBattery): ?int
    {
        if ($bodyBattery === null) {
            return null;
        }

        $events = $this->asArray($bodyBattery['activityEvents'] ?? null);

        if ($events === null) {
            return null;
        }

        $sleepImpact = 0;
        $hasSleepEvent = false;

        foreach ($events as $item) {
            $event = $this->asAssocArray($item);

            if ($event === null || strtoupper((string) ($event['eventType'] ?? '')) !== 'SLEEP') {
                continue;
            }

            $impact = $this->asInt($event['bodyBatteryImpact'] ?? null);

            if ($impact !== null) {
                $sleepImpact += $impact;
                $hasSleepEvent = true;
            }
        }

        return $hasSleepEvent ? $sleepImpact : null;
    }

    private function msToSeconds(mixed $value): ?int
    {
        $ms = $this->asNonNegativeInt($value);

        return $ms !== null ? (int) round($ms / 1000) : null;
    }

    private function positiveMsToSeconds(mixed $value): ?int
    {
        $seconds = $this->msToSeconds($value);

        return ($seconds !== null && $seconds > 0) ? $seconds : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasDailySummaryData(array $data): bool
    {
        $positiveMetrics = [
            $this->asInt($data['totalSteps'] ?? null),
            $this->asFloat($data['totalDistanceMeters'] ?? null),
            $this->asInt($data['activeKilocalories'] ?? null),
            $this->asInt($data['moderateIntensityMinutes'] ?? null),
            $this->asInt($data['vigorousIntensityMinutes'] ?? null),
            $this->asFloat($data['floorsAscended'] ?? null),
        ];

        foreach ($positiveMetrics as $value) {
            if ($value !== null && $value > 0) {
                return true;
            }
        }

        $presentMetrics = [
            $this->asInt($data['restingHeartRate'] ?? null),
            $this->asInt($data['minHeartRate'] ?? null),
            $this->asInt($data['maxHeartRate'] ?? null),
            $this->asNonNegativeInt($data['averageStressLevel'] ?? null),
            $this->asInt($data['bodyBatteryMostRecentValue'] ?? null),
            $this->asInt($data['bodyBatteryHighestValue'] ?? null),
            $this->asInt($data['bodyBatteryLowestValue'] ?? null),
            $this->asInt($data['bodyBatteryChargedValue'] ?? null),
            $this->asInt($data['bodyBatteryDrainedValue'] ?? null),
            $this->asFloat($data['avgWakingRespirationValue'] ?? null),
            $this->asFloat($data['averageSpo2'] ?? null),
        ];

        foreach ($presentMetrics as $value) {
            if ($value !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int|string, mixed>  $rawValues
     * @return list<array{0: int, 1: int}>
     */
    private function normalizeIntradaySeries(array $rawValues, int $minValue): array
    {
        $filtered = [];

        foreach ($rawValues as $point) {
            if (! is_array($point)) {
                continue;
            }

            $timestampMs = $this->asInt($point[0] ?? null);
            $value = $this->asInt($point[1] ?? null);

            if ($timestampMs === null || $timestampMs <= 0 || $value === null || $value < $minValue) {
                continue;
            }

            $filtered[] = [$timestampMs, $value];
        }

        $count = count($filtered);

        if ($count <= self::MAX_INTRADAY_SERIES_POINTS) {
            return $filtered;
        }

        $step = (int) ceil($count / self::MAX_INTRADAY_SERIES_POINTS);
        $downsampled = [];

        for ($index = 0; $index < $count; $index += $step) {
            $downsampled[] = $filtered[$index];
        }

        $lastPoint = $filtered[$count - 1];

        if ($downsampled[count($downsampled) - 1] !== $lastPoint) {
            $downsampled[] = $lastPoint;
        }

        return $downsampled;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<int, float>|null
     */
    private function extractPowerCurve(array $raw): ?array
    {
        $curve = [];

        foreach (self::POWER_CURVE_DURATIONS as $seconds) {
            $value = $this->asFloat($raw["maxAvgPower_{$seconds}"] ?? null);

            if ($value !== null && $value > 0.0) {
                $curve[$seconds] = $value;
            }
        }

        return $curve !== [] ? $curve : null;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function extractUnpavedPercentage(array $raw): ?float
    {
        $splits = $this->asArray($raw['splitSummaries'] ?? null);

        if ($splits === null) {
            return null;
        }

        $paved = 0.0;
        $unpaved = 0.0;
        $hasSurfaceData = false;

        foreach ($splits as $splitItem) {
            $split = $this->asAssocArray($splitItem);

            if ($split === null) {
                continue;
            }

            $splitType = $this->asString($split['splitType'] ?? null);
            $distance = $this->asFloat($split['distance'] ?? null);

            if ($distance === null || $distance < 0.0) {
                continue;
            }

            if ($splitType === 'SURFACE_TYPE_UNPAVED') {
                $unpaved += $distance;
                $hasSurfaceData = true;
            } elseif ($splitType === 'SURFACE_TYPE_PAVED') {
                $paved += $distance;
                $hasSurfaceData = true;
            }
        }

        $total = $paved + $unpaved;

        if (! $hasSurfaceData || $total <= 0.0) {
            return null;
        }

        return round(($unpaved / $total) * 100.0, 1);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function resolveActivityStartTimes(array $raw): array
    {
        $timezone = $this->timezone();
        $local = $this->parseGarminDatetime($raw['startTimeLocal'] ?? null, $timezone);
        $utc = $this->parseGarminDatetime($raw['startTimeGMT'] ?? null, 'UTC');

        if ($utc === null && isset($raw['beginTimestamp'])) {
            $utc = $this->timestampMsToUtc($raw['beginTimestamp']);
        }

        if ($local === null && $utc !== null) {
            $local = $utc->setTimezone($timezone);
        }

        if ($utc === null && $local !== null) {
            $utc = $local->setTimezone('UTC');
        }

        return [$local, $utc];
    }

    private function findResumableBackfillRun(): ?SyncRun
    {
        $latestBackfill = SyncRun::query()
            ->where('type', 'backfill')
            ->orderByDesc('id')
            ->first();

        if ($latestBackfill !== null && $latestBackfill->isResumableBackfill()) {
            return $latestBackfill;
        }

        return null;
    }

    private function resolveBackfillCursorDate(?string $resumeFromDate): CarbonImmutable
    {
        $timezone = $this->timezone();

        if ($resumeFromDate !== null && trim($resumeFromDate) !== '') {
            return CarbonImmutable::parse($resumeFromDate, $timezone)->startOfDay();
        }

        $resumable = $this->findResumableBackfillRun();

        if ($resumable !== null && is_string($resumable->cursor_date)) {
            return CarbonImmutable::parse($resumable->cursor_date, $timezone)->startOfDay();
        }

        return $this->today();
    }

    private function resolveEarliestBackfillDate(): CarbonImmutable
    {
        $earliestLocal = Activity::query()->min('started_at_local');

        if (is_string($earliestLocal) && $earliestLocal !== '') {
            return CarbonImmutable::parse($earliestLocal, $this->timezone())->startOfDay();
        }

        return $this->today()->subYears(3)->startOfDay();
    }

    /**
     * Execute an optional Garmin endpoint call, treating 204/404 as empty data.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function callOptional(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (GarminApiException $exception) {
            if (in_array($exception->statusCode, [204, 404], true)) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * @param  (callable(string): void)|null  $onProgress
     */
    private function report(?callable $onProgress, string $message): void
    {
        if ($onProgress !== null) {
            $onProgress($message);
        }
    }

    private function timezone(): string
    {
        $timezone = config('app.display_timezone', 'UTC');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfDay();
    }

    private function asInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) round((float) $value);
        }

        return null;
    }

    private function asNonNegativeInt(mixed $value): ?int
    {
        $int = $this->asInt($value);

        return $int !== null && $int >= 0 ? $int : null;
    }

    private function asFloat(mixed $value): ?float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function asString(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed !== '' ? $trimmed : null;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function asArray(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function asAssocArray(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result !== [] ? $result : null;
    }

    private function extractDateString(mixed $value): ?string
    {
        $string = $this->asString($value);

        if ($string === null) {
            return null;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $string, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function timestampMsToLocal(mixed $value): ?CarbonImmutable
    {
        $ms = $this->asInt($value);

        if ($ms === null || $ms <= 0) {
            return null;
        }

        return CarbonImmutable::createFromTimestampMsUTC($ms);
    }

    private function timestampMsToUtc(mixed $value): ?CarbonImmutable
    {
        $ms = $this->asInt($value);

        if ($ms === null || $ms <= 0) {
            return null;
        }

        return CarbonImmutable::createFromTimestampMsUTC($ms);
    }

    private function parseGarminDatetime(mixed $value, string $timezone): ?CarbonImmutable
    {
        $string = $this->asString($value);

        if ($string === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($string, $timezone);
        } catch (Throwable) {
            return null;
        }
    }
}
