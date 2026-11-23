<?php

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
use App\Services\Garmin\ActivityClassifier;
use App\Services\Garmin\GarminSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function syncFakeJwt(): string
{
    $header = rtrim(strtr(base64_encode('{"alg":"RS256","typ":"JWT"}'), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode(['exp' => CarbonImmutable::now()->addHours(24)->getTimestamp()], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

    return "{$header}.{$payload}.sig";
}

function configureGarminForSyncTest(): void
{
    config([
        'services.garmin.key' => 'test-garmin-key',
        'services.garmin.base_url' => 'https://personal.connectapi.garmin.com',
        'app.display_timezone' => 'Pacific/Auckland',
    ]);
}

it('classifies garmin activity types into sport categories, sport disciplines, and indoor flags', function (
    string $typeKey,
    string $expectedCategory,
    ?string $expectedDiscipline,
    bool $expectedIndoor,
) {
    $classifier = new ActivityClassifier;

    expect($classifier->classify($typeKey))->toBe([
        'sport_category' => $expectedCategory,
        'sport_discipline' => $expectedDiscipline,
        'is_indoor' => $expectedIndoor,
    ]);
})->with([
    'running' => ['running', 'running', 'road', false],
    'trail running' => ['trail_running', 'running', 'trail', false],
    'track running' => ['track_running', 'running', 'track', false],
    'treadmill running' => ['treadmill_running', 'running', 'road', true],
    'road biking' => ['road_biking', 'biking', 'road', false],
    'gravel cycling' => ['gravel_cycling', 'biking', 'gravel', false],
    'indoor cycling' => ['indoor_cycling', 'biking', 'road', true],
    'virtual ride' => ['virtual_ride', 'biking', 'road', true],
    'mountain biking' => ['mountain_biking', 'biking', 'mtb', false],
    'e-bike mountain' => ['e_bike_mountain', 'biking', 'ebike', false],
    'commuting' => ['commuting', 'biking', 'commute', false],
    'generic cycling' => ['cycling', 'biking', 'other', false],
    'walking' => ['walking', 'walking', null, false],
    'hiking' => ['hiking', 'hiking', null, false],
    'lap swimming' => ['lap_swimming', 'swimming', null, true],
    'open water swimming' => ['open_water_swimming', 'swimming', null, false],
    'strength training' => ['strength_training', 'cardio', null, true],
    'unknown sport' => ['stand_up_paddleboarding', 'other', null, false],
]);

it('syncs incremental health metrics, activities, and seeds best efforts without overwriting overrides', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00:00', 'Pacific/Auckland'));
    Http::preventStrayRequests();
    configureGarminForSyncTest();

    $existingGenericRide = Activity::factory()->create([
        'garmin_activity_id' => 9003,
        'activity_type_key' => 'cycling',
        'sport_category' => 'biking',
        'sport_discipline' => 'other',
        'discipline_override' => 'road',
        'streams_synced_at' => CarbonImmutable::parse('2026-11-20 12:00:00'),
        'started_at_utc' => CarbonImmutable::parse('2026-11-19 04:00:00'),
        'started_at_local' => CarbonImmutable::parse('2026-11-19 17:00:00'),
    ]);

    $existingRun = Activity::factory()->create([
        'garmin_activity_id' => 9001,
        'activity_type_key' => 'running',
        'sport_category' => 'running',
        'is_indoor' => false,
        'started_at_utc' => CarbonImmutable::parse('2026-11-20 19:00:00'),
        'started_at_local' => CarbonImmutable::parse('2026-11-21 08:00:00'),
    ]);

    BestEffort::factory()->create([
        'activity_id' => $existingRun->id,
        'sport' => 'running',
        'distance_key' => '1k',
        'distance_meters' => 1000.0,
        'elapsed_seconds' => 240.0,
        'source' => 'fit_stream',
        'override_seconds' => 238.5,
        'is_excluded' => false,
    ]);

    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_contains($url, '/personalapitoken-service/')) {
            return Http::response(syncFakeJwt(), 200);
        }

        if (str_contains($url, '/biometric-service/heartRateZones/v2')) {
            return Http::response([
                [
                    'sport' => 'RUNNING',
                    'trainingMethod' => 'HR_MAX',
                    'maxHeartRateUsed' => 197,
                    'restingHeartRateUsed' => 47,
                    'lactateThresholdHeartRateUsed' => 177,
                    'zone1Floor' => 130,
                    'zone2Floor' => 144,
                    'zone3Floor' => 156,
                    'zone4Floor' => 169,
                    'zone5Floor' => 183,
                ],
            ], 200);
        }

        if (str_contains($url, '/usersummary-service/usersummary/daily')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $date = is_string($query['calendarDate'] ?? null) ? $query['calendarDate'] : '2026-11-21';

            return Http::response([
                'calendarDate' => $date,
                'durationInMilliseconds' => 162000000,
                'totalSteps' => 9420,
                'dailyStepGoal' => 8000,
                'totalDistanceMeters' => 7650,
                'totalKilocalories' => 2450,
                'activeKilocalories' => 620,
                'bmrKilocalories' => 1830,
                'restingHeartRate' => 47,
                'lastSevenDaysAvgRestingHeartRate' => 48,
                'minHeartRate' => 41,
                'maxHeartRate' => 171,
                'averageStressLevel' => 24,
                'maxStressLevel' => 82,
                'stressQualifier' => 'BALANCED',
                'restStressDuration' => 42000,
                'lowStressDuration' => 7200,
                'mediumStressDuration' => 1800,
                'highStressDuration' => 360,
                'bodyBatteryMostRecentValue' => 72,
                'bodyBatteryHighestValue' => 96,
                'bodyBatteryLowestValue' => 38,
                'bodyBatteryChargedValue' => 64,
                'bodyBatteryDrainedValue' => 52,
                'bodyBatteryDuringSleep' => 58,
                'moderateIntensityMinutes' => 35,
                'vigorousIntensityMinutes' => 25,
                'intensityMinutesGoal' => 150,
                'floorsAscended' => 14.2,
                'floorsDescended' => 12.0,
                'userFloorsAscendedGoal' => 10,
                'avgWakingRespirationValue' => 13.5,
                'lowestRespirationValue' => 9.0,
                'highestRespirationValue' => 19.0,
                'averageSpo2' => 96.0,
                'lowestSpo2' => 90.0,
                'latestSpo2' => 97.0,
            ], 200);
        }

        if (str_contains($url, '/wellness-service/wellness/dailyHeartRate')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $date = is_string($query['date'] ?? null) ? $query['date'] : '2026-11-21';

            return Http::response([
                'calendarDate' => $date,
                'heartRateValues' => [
                    [1795172400000, 48],
                    [1795172520000, null],
                    [1795172640000, 56],
                ],
            ], 200);
        }

        if (str_contains($url, '/wellness-service/wellness/bodyBattery/reports/daily')) {
            return Http::response([
                [
                    'date' => '2026-11-21',
                    'charged' => 64,
                    'drained' => 52,
                    'bodyBatteryValuesArray' => [
                        [1795172400000, 44],
                        [1795190400000, 96],
                        [1795212000000, 72],
                    ],
                ],
            ], 200);
        }

        if (str_contains($url, '/usersummary-service/stats/hydration/daily/')) {
            return Http::response([
                ['calendarDate' => '2026-11-21', 'valueInML' => 2250],
            ], 200);
        }

        if (str_contains($url, '/sleep-service/sleep/dailySleepSummariesByDate')) {
            return Http::response([
                [
                    'calendarDate' => '2026-11-21',
                    'sleepTimeSeconds' => 28800,
                    'deepSleepSeconds' => 5400,
                    'lightSleepSeconds' => 17100,
                    'remSleepSeconds' => 6300,
                    'awakeSleepSeconds' => 900,
                    'napTimeSeconds' => 0,
                    'sleepStartTimestampLocal' => 1795213800000,
                    'sleepEndTimestampLocal' => 1795243500000,
                    'sleepScores' => [
                        'overall' => ['value' => 86],
                    ],
                ],
            ], 200);
        }

        if (str_contains($url, '/sleep-service/sleep/dailySleep')) {
            return Http::response([
                'calendarDate' => '2026-11-21',
                'sleepTimeSeconds' => null,
                'sleepNeed' => ['calendarDate' => '2026-11-21', 'actual' => 480],
                'nextSleepNeed' => ['calendarDate' => '2026-11-22', 'actual' => 470],
            ], 200);
        }

        if (str_contains($url, '/hrv-service/hrv/daily/')) {
            return Http::response([
                'hrvSummaries' => [
                    [
                        'calendarDate' => '2026-11-21',
                        'weeklyAvg' => 54,
                        'lastNightAvg' => 58,
                        'lastNight5MinHigh' => 81,
                        'status' => 'BALANCED',
                        'feedbackPhrase' => 'HRV_BALANCED_2',
                        'baseline' => [
                            'lowUpper' => 39,
                            'balancedLow' => 43,
                            'balancedUpper' => 59,
                        ],
                    ],
                ],
            ], 200);
        }

        if (str_contains($url, '/metrics-service/metrics/maxmet/daily/')) {
            return Http::response([
                [
                    'generic' => [
                        'calendarDate' => '2026-11-21',
                        'vo2MaxPreciseValue' => 47.4,
                        'vo2MaxValue' => 47,
                        'sport' => 'RUNNING',
                        'subSport' => 'GENERIC',
                    ],
                    'cycling' => [
                        'calendarDate' => '2026-11-21',
                        'vo2MaxPreciseValue' => 51.2,
                        'vo2MaxValue' => 51,
                        'sport' => 'CYCLING',
                        'subSport' => 'ROAD',
                    ],
                ],
            ], 200);
        }

        if (str_contains($url, '/weight-service/weight/range/')) {
            return Http::response([
                'dailyWeightSummaries' => [
                    [
                        'summaryDate' => '2026-11-21',
                        'allWeightMetrics' => [
                            [
                                'samplePk' => 1795212000123,
                                'calendarDate' => '2026-11-21',
                                'timestampGMT' => 1795165200000,
                                'weight' => 82400,
                                'bmi' => 24.6,
                                'bodyFat' => 17.5,
                                'bodyWater' => 56.2,
                                'boneMass' => 3520,
                                'muscleMass' => 64800,
                                'sourceType' => 'INDEX_SCALE',
                            ],
                        ],
                    ],
                ],
            ], 200);
        }

        if (str_contains($url, '/goal-service/goal/user/effective/weightgoal/')) {
            return Http::response([
                'userGoals' => [
                    [
                        'userGoalCategory' => 'MANUAL',
                        'userGoalType' => 'WEIGHT_GRAMS',
                        'startDate' => '2026-09-04',
                        'endDate' => null,
                        'goalValue' => 85000,
                        'updateDate' => '2026-09-04T08:40:40.0',
                    ],
                ],
                'userGoalRanges' => null,
            ], 200);
        }

        if (str_contains($url, '/fitnessage-service/fitnessage/v2/')) {
            return Http::response([
                'chronologicalAge' => 43,
                'fitnessAge' => 35.4,
                'previousFitnessAge' => 35.8,
                'components' => ['rhr' => ['value' => 47]],
            ], 200);
        }

        if (str_contains($url, '/activitylist-service/activities/search/activities')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $start = (int) ($query['start'] ?? 0);

            if ($start > 0) {
                return Http::response([], 200);
            }

            return Http::response([
                [
                    'activityId' => 9001,
                    'activityName' => 'Hagley Park Tempo',
                    'startTimeLocal' => '2026-11-21 08:00:00',
                    'startTimeGMT' => '2026-11-20 19:00:00',
                    'activityType' => ['typeKey' => 'running'],
                    'distance' => 10050.5,
                    'duration' => 2710.0,
                    'movingDuration' => 2690.0,
                    'elapsedDuration' => 2720.0,
                    'elevationGain' => 45.0,
                    'elevationLoss' => 42.0,
                    'averageSpeed' => 3.71,
                    'maxSpeed' => 4.45,
                    'calories' => 680,
                    'averageHR' => 158,
                    'maxHR' => 176,
                    'averageRunningCadenceInStepsPerMinute' => 172.5,
                    'maxRunningCadenceInStepsPerMinute' => 184.0,
                    'avgStrideLength' => 126.4,
                    'steps' => 7790,
                    'aerobicTrainingEffect' => 3.8,
                    'anaerobicTrainingEffect' => 1.1,
                    'activityTrainingLoad' => 142.5,
                    'trainingEffectLabel' => 'TEMPO',
                    'differenceBodyBattery' => -14,
                    'fastestSplit_1000' => 255.0,
                    'fastestSplit_1609' => 418.0,
                    'fastestSplit_5000' => 1320.0,
                    'hrTimeInZone_1' => 120.0,
                    'hrTimeInZone_2' => 480.0,
                    'hrTimeInZone_3' => 1650.0,
                    'hrTimeInZone_4' => 420.0,
                    'hrTimeInZone_5' => 20.0,
                ],
                [
                    'activityId' => 9002,
                    'activityName' => 'Port Hills Road Loop',
                    'startTimeLocal' => '2026-11-20 17:00:00',
                    'startTimeGMT' => '2026-11-20 04:00:00',
                    'activityType' => ['typeKey' => 'road_biking'],
                    'distance' => 45200.0,
                    'duration' => 5400.0,
                    'averageBikingCadenceInRevPerMinute' => 84.0,
                    'avgPower' => 212.0,
                    'maxPower' => 890.0,
                    'normPower' => 235.0,
                    'functionalThresholdPower' => 274.0,
                    'trainingStressScore' => 118.4,
                    'intensityFactor' => 0.86,
                    'avgLeftBalance' => 49.2,
                    'max20MinPower' => 264.0,
                    'maxAvgPower_1' => 890.0,
                    'maxAvgPower_60' => 410.0,
                    'maxAvgPower_1200' => 264.0,
                    'fastestSplit_40000' => 4780.0,
                    'powerTimeInZone_1' => 600.0,
                    'powerTimeInZone_4' => 1800.0,
                ],
                [
                    'activityId' => 9003,
                    'activityName' => 'Commute Ride',
                    'startTimeLocal' => '2026-11-19 17:00:00',
                    'startTimeGMT' => '2026-11-19 04:00:00',
                    'activityType' => ['typeKey' => 'cycling'],
                    'distance' => 12400.0,
                    'duration' => 1800.0,
                ],
                [
                    'activityId' => 9004,
                    'activityName' => 'Adventure Park Flow',
                    'startTimeLocal' => '2026-11-18 16:00:00',
                    'startTimeGMT' => '2026-11-18 03:00:00',
                    'activityType' => ['typeKey' => 'mountain_biking'],
                    'distance' => 16500.0,
                    'duration' => 3600.0,
                    'elevationGain' => 640.0,
                    'elevationLoss' => 645.0,
                    'grit' => 24.5,
                    'avgFlow' => 2.8,
                    'jumpCount' => 12,
                    'splitSummaries' => [
                        ['splitType' => 'SURFACE_TYPE_PAVED', 'distance' => 2000.0],
                        ['splitType' => 'SURFACE_TYPE_UNPAVED', 'distance' => 8000.0],
                    ],
                ],
            ], 200);
        }

        return Http::response([], 404);
    });

    $run = app(GarminSyncService::class)->syncIncremental(days: 2);

    expect($run->status)->toBe('completed')
        ->and($run->type)->toBe('incremental')
        ->and($run->items_synced)->toBeGreaterThan(0);

    expect(HeartRateZone::query()->where('sport', 'RUNNING')->first()?->max_hr)->toBe(197);

    $todayMetric = DailyMetric::query()->where('calendar_date', '2026-11-21')->first();
    expect($todayMetric)->not->toBeNull()
        ->and($todayMetric->day_duration_seconds)->toBe(162000)
        ->and($todayMetric->steps)->toBe(9420)
        ->and($todayMetric->hydration_ml)->toBe(2250)
        ->and($todayMetric->heart_rate_series)->toBe([[1795172400000, 48], [1795172640000, 56]])
        ->and($todayMetric->body_battery_series)->toHaveCount(3);

    $sleep = SleepRecord::query()->where('calendar_date', '2026-11-21')->first();
    expect($sleep)->not->toBeNull()
        ->and($sleep->sleep_seconds)->toBe(28800)
        ->and($sleep->sleep_score)->toBe(86)
        ->and($sleep->sleep_need_minutes)->toBe(480)
        ->and($sleep->next_sleep_need_minutes)->toBe(470);

    expect(HrvRecord::query()->where('calendar_date', '2026-11-21')->first()?->last_night_avg)->toBe(58);
    expect(Vo2MaxRecord::query()->count())->toBe(2);
    expect(FitnessAgeRecord::query()->where('calendar_date', '2026-11-21')->first()?->fitness_age)->toBe(35.4);
    expect(WeightMeasurement::query()->where('garmin_sample_pk', 1795212000123)->first()?->weight_grams)->toBe(82400);
    expect(WeightGoal::query()->where('start_date', '2026-09-04')->first()?->goal_grams)->toBe(85000);

    // Preserved local discipline override & streams_synced_at
    $existingGenericRide->refresh();
    expect($existingGenericRide->discipline_override)->toBe('road')
        ->and($existingGenericRide->streams_synced_at)->not->toBeNull();

    // Road power curve & MTB unpaved pct mapped cleanly
    $road = Activity::query()->where('garmin_activity_id', 9002)->first();
    expect($road?->power_curve)->toEqual(['1' => 890, '60' => 410, '1200' => 264]);

    $mtb = Activity::query()->where('garmin_activity_id', 9004)->first();
    expect($mtb?->unpaved_pct)->toBe(80.0)
        ->and($mtb?->grit)->toBe(24.5)
        ->and($mtb?->jump_count)->toBe(12);

    // Best effort fit_stream + override_seconds was NOT overwritten, while 1mi, 5k, and 40k were seeded
    $existing1k = BestEffort::query()
        ->where('activity_id', $existingRun->id)
        ->where('distance_key', '1k')
        ->first();
    expect($existing1k?->source)->toBe('fit_stream')
        ->and($existing1k?->elapsed_seconds)->toBe(240.0)
        ->and($existing1k?->override_seconds)->toBe(238.5);

    expect(BestEffort::query()->where('activity_id', $existingRun->id)->where('distance_key', '5k')->first()?->elapsed_seconds)->toBe(1320.0);
    expect(BestEffort::query()->where('sport', 'road_biking')->where('distance_key', '40k')->first()?->elapsed_seconds)->toBe(4780.0);
});

it('stops backfill early after four consecutive empty 28-day windows and clears cursor on completion', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00:00', 'Pacific/Auckland'));
    Http::preventStrayRequests();
    configureGarminForSyncTest();

    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_contains($url, '/personalapitoken-service/')) {
            return Http::response(syncFakeJwt(), 200);
        }

        if (str_contains($url, '/activitylist-service/activities/search/activities')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if ((int) ($query['start'] ?? 0) === 0) {
                return Http::response([
                    [
                        'activityId' => 111,
                        'activityName' => 'Old Run',
                        'startTimeLocal' => '2025-01-01 08:00:00',
                        'startTimeGMT' => '2024-12-31 19:00:00',
                        'activityType' => ['typeKey' => 'running'],
                        'distance' => 5000,
                        'duration' => 1500,
                    ],
                ], 200);
            }

            return Http::response([], 200);
        }

        return Http::response([], 200);
    });

    $run = app(GarminSyncService::class)->syncBackfill();

    expect($run->status)->toBe('completed')
        ->and($run->type)->toBe('backfill')
        ->and($run->cursor_date)->toBeNull()
        ->and(Activity::query()->count())->toBe(1);
});

it('runs the garmin:sync artisan command cleanly', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00:00', 'Pacific/Auckland'));
    Http::preventStrayRequests();
    configureGarminForSyncTest();

    Http::fake([
        'https://personal.connectapi.garmin.com/personalapitoken-service/*' => Http::response(syncFakeJwt(), 200),
        '*' => Http::response([], 200),
    ]);

    $this->artisan('garmin:sync', ['--days' => 1])
        ->assertSuccessful();

    expect(SyncRun::query()->latest('id')->first()?->status)->toBe('completed');
});

it('backfills daily summaries in batched v2 range requests without overwriting seven_day_avg_rhr and recovers from HTTP 429', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00:00', 'Pacific/Auckland'));
    Sleep::fake(syncWithCarbon: true);
    Http::preventStrayRequests();
    configureGarminForSyncTest();

    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-21',
        'resting_hr' => 46,
        'seven_day_avg_rhr' => 48,
    ]);

    $summaryCalls = 0;

    Http::fake(function (Request $request) use (&$summaryCalls) {
        $url = $request->url();

        if (str_contains($url, '/personalapitoken-service/')) {
            return Http::response(syncFakeJwt(), 200);
        }

        if (str_contains($url, '/activitylist-service/activities/search/activities')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if ((int) ($query['start'] ?? 0) === 0) {
                return Http::response([
                    [
                        'activityId' => 777,
                        'activityName' => 'Morning Run',
                        'startTimeLocal' => '2026-11-10 07:00:00',
                        'startTimeGMT' => '2026-11-09 18:00:00',
                        'activityType' => ['typeKey' => 'running'],
                        'distance' => 8000,
                        'duration' => 2400,
                    ],
                ], 200);
            }

            return Http::response([], 200);
        }

        if (str_contains($url, '/usersummary-service/v2/dailysummaries')) {
            $summaryCalls++;

            if ($summaryCalls === 1) {
                return Http::response([
                    'count' => 101,
                    'limit' => 100,
                    'retryInSeconds' => 5,
                    'rateLimitMetaData' => 2,
                ], 429);
            }

            return Http::response([
                'data' => [
                    [
                        'calendarDate' => '2026-11-21',
                        'wellnessChronology' => [
                            'totalDurationInMillis' => 147600000,
                        ],
                        'movement' => [
                            'steps' => ['goal' => 8000, 'value' => 13098, 'distanceInMeters' => 12888],
                            'floorsAscended' => ['goal' => 10, 'value' => 8],
                            'floorsDescended' => ['value' => 7],
                        ],
                        'calories' => ['burnedResting' => 1836, 'burnedActive' => 652, 'burnedTotal' => 2488],
                        'heartRate' => ['minValue' => 40, 'maxValue' => 161, 'restingValue' => 47],
                        'intensityMinutes' => ['goal' => 300, 'moderate' => 12, 'vigorous' => 44],
                        'stress' => [
                            'avgLevel' => 17,
                            'maxLevel' => 87,
                            'qualifier' => 'calm',
                            'restDurationInMillis' => 41760000,
                            'lowStressDurationInMillis' => 7380000,
                            'mediumStressDurationInMillis' => 1980000,
                            'highStressDurationInMillis' => 240000,
                        ],
                        'bodyBattery' => [
                            'minValue' => 29,
                            'maxValue' => 84,
                            'chargedValue' => 27,
                            'drainedValue' => 59,
                            'latestValue' => 29,
                            'activityEvents' => [
                                ['eventType' => 'SLEEP', 'bodyBatteryImpact' => 49],
                                ['eventType' => 'ACTIVITY', 'bodyBatteryImpact' => -10],
                            ],
                        ],
                        'respiration' => ['avgValue' => 13.0, 'minValue' => 7.0, 'maxValue' => 38.0],
                        'pulseOx' => ['avgValue' => 93.0, 'minValue' => 86.0, 'latestValue' => 96.0],
                    ],
                ],
            ], 200);
        }

        return Http::response([], 200);
    });

    $progress = [];
    $run = app(GarminSyncService::class)->syncBackfill(
        onProgress: function (string $message) use (&$progress): void {
            $progress[] = $message;
        },
    );

    expect($run->status)->toBe('completed')
        ->and($run->cursor_date)->toBeNull();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/usersummary-service/usersummary/daily'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/usersummary-service/v2/dailysummaries'));

    $metric = DailyMetric::query()->where('calendar_date', '2026-11-21')->first();
    expect($metric)->not->toBeNull()
        ->and($metric->day_duration_seconds)->toBe(147600)
        ->and($metric->steps)->toBe(13098)
        ->and($metric->resting_hr)->toBe(47)
        ->and($metric->seven_day_avg_rhr)->toBe(48)
        ->and($metric->rest_stress_seconds)->toBe(41760)
        ->and($metric->high_stress_seconds)->toBe(240)
        ->and($metric->body_battery_sleep)->toBe(49)
        ->and($metric->avg_spo2)->toBe(93.0);

    expect(implode("\n", $progress))->toContain('Rate limited');
    Sleep::assertSleptTimes(1);
});

it('syncs missing fitness age dates in incremental sync while skipping stored past dates and refreshing today', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00:00', 'Pacific/Auckland'));
    Http::preventStrayRequests();
    configureGarminForSyncTest();

    // 2026-11-20 already exists (should be skipped); 2026-11-19 is missing (should be fetched); 2026-11-21 is today (should be refreshed)
    FitnessAgeRecord::factory()->create([
        'calendar_date' => '2026-11-20',
        'chronological_age' => 43.0,
        'fitness_age' => 36.0,
    ]);
    FitnessAgeRecord::factory()->create([
        'calendar_date' => '2026-11-21',
        'chronological_age' => 43.0,
        'fitness_age' => 35.9,
    ]);

    $requestedDates = [];

    Http::fake(function (Request $request) use (&$requestedDates) {
        $url = $request->url();

        if (str_contains($url, '/personalapitoken-service/')) {
            return Http::response(syncFakeJwt(), 200);
        }

        if (preg_match('#/fitnessage-service/fitnessage/v2/(\d{4}-\d{2}-\d{2})#', $url, $matches) === 1) {
            $date = $matches[1];
            $requestedDates[] = $date;

            return Http::response([
                'chronologicalAge' => 43,
                'fitnessAge' => $date === '2026-11-21' ? 34.8 : 36.4,
            ], 200);
        }

        return Http::response([], 200);
    });

    app(GarminSyncService::class)->syncIncremental(days: 3);

    expect($requestedDates)->toBe(['2026-11-21', '2026-11-19'])
        ->and(FitnessAgeRecord::query()->where('calendar_date', '2026-11-21')->first()?->fitness_age)->toBe(34.8)
        ->and(FitnessAgeRecord::query()->where('calendar_date', '2026-11-20')->first()?->fitness_age)->toBe(36.0)
        ->and(FitnessAgeRecord::query()->where('calendar_date', '2026-11-19')->first()?->fitness_age)->toBe(36.4);
});

it('samples fitness age weekly during backfill and skips already-stored historical dates', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00:00', 'Pacific/Auckland'));
    Http::preventStrayRequests();
    configureGarminForSyncTest();

    // Existing historical checkpoint should not be re-fetched
    FitnessAgeRecord::factory()->create([
        'calendar_date' => '2026-11-14',
        'chronological_age' => 43.0,
        'fitness_age' => 36.1,
    ]);

    $requestedDates = [];

    Http::fake(function (Request $request) use (&$requestedDates) {
        $url = $request->url();

        if (str_contains($url, '/personalapitoken-service/')) {
            return Http::response(syncFakeJwt(), 200);
        }

        if (str_contains($url, '/activitylist-service/activities/search/activities')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if ((int) ($query['start'] ?? 0) === 0) {
                return Http::response([
                    [
                        'activityId' => 888,
                        'activityName' => 'Earlier Run',
                        'startTimeLocal' => '2026-10-20 07:00:00',
                        'startTimeGMT' => '2026-10-19 18:00:00',
                        'activityType' => ['typeKey' => 'running'],
                        'distance' => 8000,
                        'duration' => 2400,
                    ],
                ], 200);
            }

            return Http::response([], 200);
        }

        if (preg_match('#/fitnessage-service/fitnessage/v2/(\d{4}-\d{2}-\d{2})#', $url, $matches) === 1) {
            $requestedDates[] = $matches[1];

            return Http::response([
                'chronologicalAge' => 43,
                'fitnessAge' => 35.5,
            ], 200);
        }

        return Http::response([], 200);
    });

    app(GarminSyncService::class)->syncBackfill();

    // Window 1 (2026-10-25..2026-11-21): 11-21, 11-14 (skipped), 11-07, 10-31
    // Window 2 (2026-10-20..2026-10-24): 10-24
    expect($requestedDates)->toBe([
        '2026-11-21',
        '2026-11-07',
        '2026-10-31',
        '2026-10-24',
    ])
        ->and(FitnessAgeRecord::query()->count())->toBe(5);
});

it('starts backfill from today instead of a stale interrupted run when a newer backfill already completed', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00:00', 'Pacific/Auckland'));
    Http::preventStrayRequests();
    configureGarminForSyncTest();

    // Older interrupted backfill left stuck in "running" with a 2021 cursor
    SyncRun::query()->create([
        'type' => 'backfill',
        'status' => 'running',
        'started_at' => CarbonImmutable::parse('2026-11-20 07:00:00'),
        'cursor_date' => '2021-06-12',
        'items_synced' => 100,
    ]);

    // Later completed backfill
    SyncRun::query()->create([
        'type' => 'backfill',
        'status' => 'completed',
        'started_at' => CarbonImmutable::parse('2026-11-20 08:00:00'),
        'finished_at' => CarbonImmutable::parse('2026-11-20 08:10:00'),
        'cursor_date' => null,
        'items_synced' => 200,
    ]);

    $requestedDates = [];

    Http::fake(function (Request $request) use (&$requestedDates) {
        $url = $request->url();

        if (str_contains($url, '/personalapitoken-service/')) {
            return Http::response(syncFakeJwt(), 200);
        }

        if (preg_match('#/fitnessage-service/fitnessage/v2/(\d{4}-\d{2}-\d{2})#', $url, $matches) === 1) {
            $requestedDates[] = $matches[1];

            return Http::response([
                'chronologicalAge' => 43,
                'fitnessAge' => 35.2,
            ], 200);
        }

        return Http::response([], 200);
    });

    app(GarminSyncService::class)->syncBackfill(untilDate: '2026-11-01');

    // Must start from today (2026-11-21), NOT the stale 2021-06-12 cursor
    expect($requestedDates)->toBe([
        '2026-11-21',
        '2026-11-14',
        '2026-11-07',
    ]);
});

it('backs off to once-per-window fitness age sampling when consecutive historical windows return null fitness_age', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00:00', 'Pacific/Auckland'));
    Http::preventStrayRequests();
    configureGarminForSyncTest();

    $requestedDates = [];

    Http::fake(function (Request $request) use (&$requestedDates) {
        $url = $request->url();

        if (str_contains($url, '/personalapitoken-service/')) {
            return Http::response(syncFakeJwt(), 200);
        }

        if (str_contains($url, '/usersummary-service/v2/dailysummaries')) {
            // Return 1 daily summary per window so backfill doesn't stop early on empty windows
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return Http::response([
                'data' => [
                    [
                        'calendarDate' => $query['endDate'] ?? '2026-11-21',
                        'movement' => ['steps' => ['value' => 5000]],
                    ],
                ],
            ], 200);
        }

        if (preg_match('#/fitnessage-service/fitnessage/v2/(\d{4}-\d{2}-\d{2})#', $url, $matches) === 1) {
            $requestedDates[] = $matches[1];

            // Chronological age present, fitnessAge null (stale / pre-feature era)
            return Http::response([
                'chronologicalAge' => 35,
                'fitnessAge' => null,
            ], 200);
        }

        return Http::response([], 200);
    });

    // 3 full 28-day windows:
    // Window 1 (2026-10-25..2026-11-21): weekly (4 calls) -> empty count = 1
    // Window 2 (2026-09-27..2026-10-24): weekly (4 calls) -> empty count = 2
    // Window 3 (2026-08-30..2026-09-26): backed off to 1 call (2026-09-26 only)
    app(GarminSyncService::class)->syncBackfill(untilDate: '2026-08-30');

    expect($requestedDates)->toBe([
        '2026-11-21',
        '2026-11-14',
        '2026-11-07',
        '2026-10-31',
        '2026-10-24',
        '2026-10-17',
        '2026-10-10',
        '2026-10-03',
        '2026-09-26',
    ]);
});

it('retries stored null fitness_age rows during incremental sync when Garmin later supplies an estimate', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00:00', 'Pacific/Auckland'));
    Http::preventStrayRequests();
    configureGarminForSyncTest();

    // 2026-11-20 has a valid estimate (skip), 2026-11-19 has null fitness_age (must retry)
    FitnessAgeRecord::factory()->create([
        'calendar_date' => '2026-11-20',
        'chronological_age' => 43.0,
        'fitness_age' => 36.0,
    ]);
    FitnessAgeRecord::factory()->create([
        'calendar_date' => '2026-11-19',
        'chronological_age' => 43.0,
        'fitness_age' => null,
    ]);

    $requestedDates = [];

    Http::fake(function (Request $request) use (&$requestedDates) {
        $url = $request->url();

        if (str_contains($url, '/personalapitoken-service/')) {
            return Http::response(syncFakeJwt(), 200);
        }

        if (preg_match('#/fitnessage-service/fitnessage/v2/(\d{4}-\d{2}-\d{2})#', $url, $matches) === 1) {
            $requestedDates[] = $matches[1];

            return Http::response([
                'chronologicalAge' => 43,
                'fitnessAge' => 35.8,
            ], 200);
        }

        return Http::response([], 200);
    });

    app(GarminSyncService::class)->syncIncremental(days: 3);

    expect($requestedDates)->toBe(['2026-11-21', '2026-11-19'])
        ->and(FitnessAgeRecord::query()->where('calendar_date', '2026-11-19')->first()?->fitness_age)->toBe(35.8);
});

it('does not early-stop a repeat backfill across four windows containing already-synced fitness age records', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00:00', 'Pacific/Auckland'));
    Http::preventStrayRequests();
    configureGarminForSyncTest();

    // Pre-populate all weekly Fitness Age checkpoints for 4 full 28-day windows (2026-08-02..2026-11-21)
    // except the 5th older window (2026-07-05..2026-08-01), which has untouched history.
    $cursor = CarbonImmutable::parse('2026-11-21');
    for ($week = 0; $week < 16; $week++) {
        FitnessAgeRecord::factory()->create([
            'calendar_date' => $cursor->subDays($week * 7)->format('Y-m-d'),
            'chronological_age' => 43.0,
            'fitness_age' => 36.0,
        ]);
    }

    $requestedDates = [];

    Http::fake(function (Request $request) use (&$requestedDates) {
        $url = $request->url();

        if (str_contains($url, '/personalapitoken-service/')) {
            return Http::response(syncFakeJwt(), 200);
        }

        if (preg_match('#/fitnessage-service/fitnessage/v2/(\d{4}-\d{2}-\d{2})#', $url, $matches) === 1) {
            $requestedDates[] = $matches[1];

            // Return null for today so even window 1 writes 0 new items
            if ($matches[1] === '2026-11-21') {
                return Http::response([], 200);
            }

            return Http::response([
                'chronologicalAge' => 43,
                'fitnessAge' => 37.5,
            ], 200);
        }

        return Http::response([], 200);
    });

    app(GarminSyncService::class)->syncBackfill(untilDate: '2026-07-10');

    // Must reach the 5th window (2026-08-01, 2026-07-25, 2026-07-18, 2026-07-11) instead of stopping after 4 windows
    expect($requestedDates)->toContain('2026-08-01', '2026-07-25', '2026-07-18', '2026-07-11')
        ->and(FitnessAgeRecord::query()->where('calendar_date', '2026-07-11')->first()?->fitness_age)->toBe(37.5);
});
