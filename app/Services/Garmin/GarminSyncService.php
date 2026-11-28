<?php

namespace App\Services\Garmin;

use App\Jobs\SyncActivityStreamJob;
use App\Jobs\SyncActivityWeatherJob;
use App\Models\Activity;
use App\Models\FitnessAgeRecord;
use App\Models\SyncRun;
use App\Services\Ingestion\TelemetryPersistenceService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Splitline\Core\Data\ProviderContext;
use Splitline\ProviderGarminPersonal\GarminPersonalProvider;
use Throwable;

class GarminSyncService
{
    private const int INCREMENTAL_ACTIVITY_PAGE_SIZE = 50;

    private const int INITIAL_ACTIVITY_LIMIT = 100;

    private const int BACKFILL_ACTIVITY_PAGE_SIZE = 100;

    private const int BACKFILL_WINDOW_DAYS = 28;

    private const int BACKFILL_FITNESS_AGE_STEP_DAYS = 7;

    private const int MAX_CONSECUTIVE_EMPTY_WINDOWS = 4;

    /**
     * @var (Closure(float, string): void)|null
     */
    private ?Closure $activeRateLimitWait = null;

    public function __construct(
        private readonly GarminPersonalProvider $provider,
        private readonly TelemetryPersistenceService $persistence,
    ) {}

    public static function isConfigured(): bool
    {
        return trim((string) config('services.garmin.key', '')) !== '';
    }

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
                'provider' => GarminPersonalProvider::ID,
                'type' => 'incremental',
                'status' => 'running',
                'finished_at' => null,
                'error_message' => null,
            ]);
            $syncRun = $existingRun;
        } else {
            $syncRun = SyncRun::query()->create([
                'provider' => GarminPersonalProvider::ID,
                'type' => 'incremental',
                'status' => 'running',
                'started_at' => CarbonImmutable::now(),
                'items_synced' => 0,
            ]);
        }

        $itemsSynced = 0;

        if ($onProgress !== null) {
            $this->activeRateLimitWait = function (float $waitSeconds, string $reason) use ($onProgress): void {
                $seconds = (int) ceil($waitSeconds);
                $this->report($onProgress, "Rate limited ({$reason}) — waiting {$seconds}s");
            };
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
            $this->activeRateLimitWait = null;
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

        SyncRun::supersedePendingBackfills();

        $syncRun = SyncRun::query()->create([
            'provider' => GarminPersonalProvider::ID,
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
            queueEnrichment: true,
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
        bool $queueEnrichment = true,
    ): array {
        $itemsSynced = (int) $syncRun->items_synced;
        $windowLimit = max(1, $maxWindows);
        $effectiveUntilDate = ($untilDate !== null && trim($untilDate) !== '')
            ? trim($untilDate)
            : $syncRun->until_date;

        $this->activeRateLimitWait = function (float $waitSeconds, string $reason) use ($onProgress, $syncRun): void {
            $this->updateActiveRun($syncRun, []);

            if ($onProgress !== null) {
                $seconds = (int) ceil($waitSeconds);
                $this->report($onProgress, "Rate limited ({$reason}) — waiting {$seconds}s");
            }
        };

        try {
            if (! $this->isRunStillActive($syncRun)) {
                return [
                    'completed' => true,
                    'consecutiveEmptyWindows' => $consecutiveEmptyWindows,
                    'consecutiveEmptyFitnessAgeWindows' => $consecutiveEmptyFitnessAgeWindows,
                ];
            }

            if ($includeInitialSetup && ! $syncRun->initial_setup_completed) {
                $this->report($onProgress, 'Syncing heart rate zones');
                $itemsSynced += $this->syncHeartRateZones();

                $this->report($onProgress, 'Backfilling all activities');
                $itemsSynced += $this->syncAllActivities($onProgress, $syncRun, $itemsSynced);

                if (! $this->updateActiveRun($syncRun, [
                    'initial_setup_completed' => true,
                    'items_synced' => $itemsSynced,
                ])) {
                    return [
                        'completed' => true,
                        'consecutiveEmptyWindows' => $consecutiveEmptyWindows,
                        'consecutiveEmptyFitnessAgeWindows' => $consecutiveEmptyFitnessAgeWindows,
                    ];
                }
            }

            if ($queueEnrichment) {
                if (! $this->isRunStillActive($syncRun)) {
                    return [
                        'completed' => true,
                        'consecutiveEmptyWindows' => $consecutiveEmptyWindows,
                        'consecutiveEmptyFitnessAgeWindows' => $consecutiveEmptyFitnessAgeWindows,
                    ];
                }

                $this->queueMissingActivityEnrichments($effectiveUntilDate);
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
                if (! $this->isRunStillActive($syncRun)) {
                    return [
                        'completed' => true,
                        'consecutiveEmptyWindows' => $consecutiveEmptyWindows,
                        'consecutiveEmptyFitnessAgeWindows' => $consecutiveEmptyFitnessAgeWindows,
                    ];
                }

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

                if (! $this->updateActiveRun($syncRun, [
                    'cursor_date' => $nextCursor->format('Y-m-d'),
                    'items_synced' => $itemsSynced,
                ])) {
                    return [
                        'completed' => true,
                        'consecutiveEmptyWindows' => $consecutiveEmptyWindows,
                        'consecutiveEmptyFitnessAgeWindows' => $consecutiveEmptyFitnessAgeWindows,
                    ];
                }

                $windowEnd = $nextCursor;
                $windowsProcessed++;
            }

            $completed = $windowEnd->lessThan($earliestDate)
                || $consecutiveEmptyWindows >= self::MAX_CONSECUTIVE_EMPTY_WINDOWS;

            if ($completed) {
                $this->updateActiveRun($syncRun, [
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
            $this->updateActiveRun($syncRun, [
                'status' => 'failed',
                'finished_at' => CarbonImmutable::now(),
                'items_synced' => $itemsSynced,
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            $this->activeRateLimitWait = null;
        }
    }

    public function syncHeartRateZones(): int
    {
        $synced = 0;

        foreach ($this->provider->fetchHeartRateZones($this->context()) as $zone) {
            if ($this->persistence->upsertHeartRateZone($zone)) {
                $synced++;
            }
        }

        return $synced;
    }

    public function syncDailySummary(string $date): int
    {
        $metric = $this->provider->fetchDailySummary($this->context(), $date);

        if ($metric === null) {
            return 0;
        }

        return $this->persistence->upsertDailyMetric($metric) ? 1 : 0;
    }

    /**
     * Sync a multi-day range of daily summaries in a single v2 API request.
     */
    public function syncDailySummaries(string $startDate, string $endDate): int
    {
        $synced = 0;

        foreach ($this->provider->fetchDailySummariesV2($this->context(), $startDate, $endDate) as $metric) {
            if ($this->persistence->upsertDailyMetric($metric)) {
                $synced++;
            }
        }

        return $synced;
    }

    public function syncDailyHeartRate(string $date): int
    {
        $metric = $this->provider->fetchDailyHeartRate($this->context(), $date);

        if ($metric === null) {
            return 0;
        }

        return $this->persistence->upsertDailyMetric($metric) ? 1 : 0;
    }

    public function syncDailyBodyBattery(string $startDate, string $endDate): int
    {
        $synced = 0;

        foreach ($this->provider->fetchDailyBodyBattery($this->context(), $startDate, $endDate) as $metric) {
            if ($this->persistence->upsertDailyMetric($metric)) {
                $synced++;
            }
        }

        return $synced;
    }

    public function syncDailyHydration(string $startDate, string $endDate): int
    {
        $synced = 0;

        foreach ($this->provider->fetchDailyHydration($this->context(), $startDate, $endDate) as $metric) {
            if ($this->persistence->upsertDailyMetric($metric)) {
                $synced++;
            }
        }

        return $synced;
    }

    public function syncDailySleepSummaries(string $startDate, string $endDate): int
    {
        $synced = 0;

        foreach ($this->provider->fetchSleepSummaries($this->context(), $startDate, $endDate) as $sleep) {
            if ($this->persistence->upsertSleep($sleep)) {
                $synced++;
            }
        }

        return $synced;
    }

    public function syncDailySleep(string $date): int
    {
        $sleep = $this->provider->fetchSleepForDate($this->context(), $date);

        if ($sleep === null) {
            return 0;
        }

        return $this->persistence->upsertSleep($sleep) ? 1 : 0;
    }

    public function syncDailyHrvSummaries(string $startDate, string $endDate): int
    {
        $synced = 0;

        foreach ($this->provider->fetchHrv($this->context(), $startDate, $endDate) as $hrv) {
            if ($this->persistence->upsertHrv($hrv)) {
                $synced++;
            }
        }

        return $synced;
    }

    public function syncDailyVo2Max(string $startDate, string $endDate): int
    {
        $synced = 0;

        foreach ($this->provider->fetchVo2Max($this->context(), $startDate, $endDate) as $vo2Max) {
            if ($this->persistence->upsertVo2Max($vo2Max)) {
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
        $record = $this->provider->fetchFitnessAgeForDate($this->context(), $date);

        if ($record === null) {
            return 0;
        }

        $persisted = $this->persistence->upsertFitnessAge($record);

        return $persisted && $record->fitnessAge !== null ? 1 : 0;
    }

    public function syncWeightMeasurements(string $startDate, string $endDate): int
    {
        $synced = 0;

        foreach ($this->provider->fetchWeightMeasurements($this->context(), $startDate, $endDate) as $measurement) {
            if ($this->persistence->upsertWeightMeasurement($measurement)) {
                $synced++;
            }
        }

        return $synced;
    }

    public function syncWeightGoals(string $startDate, string $endDate): int
    {
        $synced = 0;

        foreach ($this->provider->fetchWeightGoals($this->context(), $startDate, $endDate) as $goal) {
            if ($this->persistence->upsertWeightGoal($goal)) {
                $synced++;
            }
        }

        return $synced;
    }

    /**
     * Upsert a single Garmin activity summary and seed its split-based Best Efforts.
     *
     * @param  array<string, mixed>  $raw
     */
    public function upsertActivity(array $raw, bool $dispatchEnrichments = true): ?Activity
    {
        $normalized = $this->provider->mapper()->mapActivity($raw, $this->timezone());

        if ($normalized === null) {
            return null;
        }

        return $this->persistence->upsertActivity($normalized, $dispatchEnrichments);
    }

    private function syncRecentActivities(): int
    {
        $latestStartedAtUtc = Activity::withExcluded()->max('started_at_utc');
        $cutoffUtc = is_string($latestStartedAtUtc) && $latestStartedAtUtc !== ''
            ? CarbonImmutable::parse($latestStartedAtUtc, 'UTC')->subDay()
            : null;

        $synced = 0;
        $offset = 0;

        while (true) {
            $batch = $this->provider->searchActivitiesRaw($this->context(), [
                'start' => $offset,
                'limit' => self::INCREMENTAL_ACTIVITY_PAGE_SIZE,
            ]);

            if ($batch === []) {
                break;
            }

            $reachedCutoff = false;

            foreach ($batch as $raw) {
                $activity = $this->upsertActivity($raw);

                if ($activity === null) {
                    continue;
                }

                $synced++;

                if ($cutoffUtc !== null && $activity->started_at_utc->lessThan($cutoffUtc)) {
                    $reachedCutoff = true;
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
    private function syncAllActivities(
        ?callable $onProgress = null,
        ?SyncRun $syncRun = null,
        int $baseItemsSynced = 0,
    ): int {
        $synced = 0;
        $offset = 0;

        while (true) {
            if ($syncRun !== null && ! $this->isRunStillActive($syncRun)) {
                break;
            }

            $batch = $this->provider->searchActivitiesRaw($this->context(), [
                'start' => $offset,
                'limit' => self::BACKFILL_ACTIVITY_PAGE_SIZE,
            ]);

            if ($batch === []) {
                break;
            }

            foreach ($batch as $raw) {
                if ($this->upsertActivity($raw, dispatchEnrichments: false) !== null) {
                    $synced++;
                }
            }

            $batchCount = count($batch);
            $offset += $batchCount;

            $this->report($onProgress, "Synced {$synced} activities so far");

            if ($syncRun !== null && ! $this->updateActiveRun($syncRun, [
                'items_synced' => $baseItemsSynced + $synced,
            ])) {
                break;
            }

            if ($batchCount < self::BACKFILL_ACTIVITY_PAGE_SIZE) {
                break;
            }
        }

        return $synced;
    }

    /**
     * Update a SyncRun only while it is still running or queued (not superseded).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function updateActiveRun(SyncRun $syncRun, array $attributes): bool
    {
        $now = CarbonImmutable::now();

        $updated = SyncRun::query()
            ->whereKey($syncRun->id)
            ->whereIn('status', ['running', 'queued'])
            ->update([
                ...$attributes,
                'updated_at' => $now,
            ]);

        if ($updated > 0) {
            $syncRun->forceFill([
                ...$attributes,
                'updated_at' => $now,
            ]);

            return true;
        }

        return false;
    }

    private function isRunStillActive(SyncRun $syncRun): bool
    {
        return SyncRun::query()
            ->whereKey($syncRun->id)
            ->whereIn('status', ['running', 'queued'])
            ->exists();
    }

    /**
     * Queue missing FIT stream and historical weather enrichment jobs for existing activities in range.
     */
    private function queueMissingActivityEnrichments(?string $untilDate): void
    {
        $cutoffLocal = $untilDate !== null && trim($untilDate) !== ''
            ? trim($untilDate).' 00:00:00'
            : null;

        if ((bool) config('services.garmin.auto_sync_streams', true)) {
            $streamQuery = Activity::query()
                ->where('is_manual', false)
                ->awaitingStreams()
                ->orderByDesc('started_at_local')
                ->orderByDesc('id');

            if ($cutoffLocal !== null) {
                $streamQuery->where('started_at_local', '>=', $cutoffLocal);
            }

            /** @var list<int> $streamIds */
            $streamIds = array_values(array_map('intval', $streamQuery->pluck('id')->all()));

            if ($streamIds !== []) {
                DB::transaction(function () use ($streamIds): void {
                    foreach ($streamIds as $activityId) {
                        SyncActivityStreamJob::dispatch($activityId);
                    }
                });
            }
        }

        if (
            (bool) config('services.open_meteo.enabled', true)
            && (bool) config('services.open_meteo.auto_sync', true)
        ) {
            $weatherQuery = Activity::query()
                ->awaitingWeather()
                ->orderByDesc('started_at_local')
                ->orderByDesc('id');

            if ($cutoffLocal !== null) {
                $weatherQuery->where('started_at_local', '>=', $cutoffLocal);
            }

            /** @var list<int> $weatherIds */
            $weatherIds = array_values(array_map('intval', $weatherQuery->pluck('id')->all()));

            if ($weatherIds !== []) {
                DB::transaction(function () use ($weatherIds): void {
                    foreach ($weatherIds as $activityId) {
                        SyncActivityWeatherJob::dispatch($activityId);
                    }
                });
            }
        }
    }

    private function context(): ProviderContext
    {
        return new ProviderContext(
            scopeKey: 'default',
            timezone: $this->timezone(),
            credentials: [
                'api_key' => (string) config('services.garmin.key', ''),
            ],
            onRateLimitWait: $this->activeRateLimitWait,
        );
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
        $earliestLocal = Activity::withExcluded()->min('started_at_local');

        if (is_string($earliestLocal) && $earliestLocal !== '') {
            return CarbonImmutable::parse($earliestLocal, $this->timezone())->startOfDay();
        }

        return $this->today()->subYears(3)->startOfDay();
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
}
