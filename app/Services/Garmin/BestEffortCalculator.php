<?php

namespace App\Services\Garmin;

use App\Models\Activity;
use App\Models\BestEffort;
use App\Support\SportRegistry;
use Splitline\Core\Analytics\BestEffortCalculator as CoreBestEffortCalculator;
use Splitline\Core\Data\ActivityStreamData;

/**
 * Sliding-window Best Efforts calculator and persistence wrapper delegating math to splitline/core.
 *
 * @phpstan-import-type FitRecord from ActivityStreamData
 * @phpstan-import-type StreamRecord from CoreBestEffortCalculator
 * @phpstan-import-type ComputedBestEffort from CoreBestEffortCalculator
 */
class BestEffortCalculator
{
    /**
     * @var array<string, float>
     */
    public const array RUNNING_DISTANCES = CoreBestEffortCalculator::RUNNING_DISTANCES;

    /**
     * @var array<string, float>
     */
    public const array ROAD_BIKING_DISTANCES = CoreBestEffortCalculator::ROAD_BIKING_DISTANCES;

    public function __construct(
        private readonly CoreBestEffortCalculator $calculator = new CoreBestEffortCalculator,
    ) {}

    /**
     * Compute minimum elapsed time per benchmark distance using an O(N) two-pointer sliding window
     * with linear interpolation at the window end point.
     *
     * @param  list<FitRecord|StreamRecord>  $records
     * @return list<ComputedBestEffort>
     */
    public function calculate(array $records, string $sport): array
    {
        return $this->calculator->calculate($records, $sport);
    }

    /**
     * Calculate and upsert FIT stream Best Efforts for an activity while preserving manual overrides and exclusions.
     *
     * @param  list<FitRecord|StreamRecord>  $records
     */
    public function syncForActivity(Activity $activity, array $records): int
    {
        $sport = $this->resolveSport($activity);

        if ($sport === null) {
            BestEffort::query()
                ->where('activity_id', $activity->id)
                ->delete();

            return 0;
        }

        BestEffort::query()
            ->where('activity_id', $activity->id)
            ->where('sport', '!=', $sport)
            ->delete();

        $efforts = $this->calculate($records, $sport);
        $upserted = 0;

        foreach ($efforts as $effort) {
            $existing = BestEffort::query()
                ->where('activity_id', $activity->id)
                ->where('sport', $sport)
                ->where('distance_key', $effort['distance_key'])
                ->first();

            if ($existing !== null) {
                $existing->update([
                    'distance_meters' => $effort['distance_meters'],
                    'elapsed_seconds' => $effort['elapsed_seconds'],
                    'moving_seconds' => $effort['moving_seconds'],
                    'start_offset_seconds' => $effort['start_offset_seconds'],
                    'avg_hr' => $effort['avg_hr'],
                    'elevation_delta' => $effort['elevation_delta'],
                    'start_distance_meters' => $effort['start_distance_meters'],
                    'end_distance_meters' => $effort['end_distance_meters'],
                    'achieved_at' => $activity->started_at_local,
                    'source' => 'fit_stream',
                ]);
            } else {
                BestEffort::query()->create([
                    'activity_id' => $activity->id,
                    'sport' => $sport,
                    'distance_key' => $effort['distance_key'],
                    'distance_meters' => $effort['distance_meters'],
                    'elapsed_seconds' => $effort['elapsed_seconds'],
                    'moving_seconds' => $effort['moving_seconds'],
                    'start_offset_seconds' => $effort['start_offset_seconds'],
                    'avg_hr' => $effort['avg_hr'],
                    'elevation_delta' => $effort['elevation_delta'],
                    'start_distance_meters' => $effort['start_distance_meters'],
                    'end_distance_meters' => $effort['end_distance_meters'],
                    'achieved_at' => $activity->started_at_local,
                    'source' => 'fit_stream',
                    'is_excluded' => false,
                ]);
            }

            $upserted++;
        }

        return $upserted;
    }

    public function resolveSport(Activity $activity): ?string
    {
        return app(SportRegistry::class)->resolveBestEffortSport($activity);
    }
}
