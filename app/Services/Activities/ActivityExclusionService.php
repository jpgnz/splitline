<?php

namespace App\Services\Activities;

use App\Jobs\MatchActivitySegmentsJob;
use App\Models\Activity;
use App\Services\Routes\RouteMatchingService;
use App\Services\Segments\SegmentMatchingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Coordinates excluding activities from analytics/leaderboards and restoring them cleanly.
 */
class ActivityExclusionService
{
    public function __construct(
        private readonly RouteMatchingService $routeMatchingService = new RouteMatchingService,
        private readonly SegmentMatchingService $segmentMatchingService = new SegmentMatchingService,
    ) {}

    /**
     * Exclude an activity from all stats, leaderboards, matched routes, segments, and future sync overwrites.
     */
    public function exclude(Activity $activity): Activity
    {
        if ($activity->isExcluded()) {
            return $activity;
        }

        DB::transaction(function () use ($activity): void {
            $activity->update([
                'excluded_at' => CarbonImmutable::now('UTC'),
            ]);

            $this->routeMatchingService->removeActivity($activity);
            $this->segmentMatchingService->removeActivity($activity);
        });

        return $activity->refresh();
    }

    /**
     * Restore a previously excluded activity back into stats, matched routes, and segment matching.
     */
    public function restore(Activity $activity): Activity
    {
        if (! $activity->isExcluded()) {
            return $activity;
        }

        DB::transaction(function () use ($activity): void {
            $activity->update([
                'excluded_at' => null,
                'route_match_skipped_at' => null,
                'segments_scanned_at' => null,
            ]);
        });

        $activity->refresh();
        $stream = $activity->stream()->first();

        if ($stream !== null) {
            try {
                $this->routeMatchingService->matchActivity($activity, $stream);
            } catch (Throwable $exception) {
                report($exception);
            }

            MatchActivitySegmentsJob::dispatch($activity->id)->afterCommit();
        }

        return $activity->refresh();
    }
}
