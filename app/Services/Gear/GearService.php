<?php

namespace App\Services\Gear;

use App\Models\Activity;
use App\Models\GearItem;
use Illuminate\Support\Facades\DB;

class GearService
{
    private const int RECENT_ACTIVITIES_LIMIT = 8;

    private const float WARNING_THRESHOLD_PCT = 85.0;

    private const float EXCEEDED_THRESHOLD_PCT = 100.0;

    /**
     * List all active and retired gear items with aggregated mileage, alert status, and recent activities.
     *
     * @return array{
     *     active: list<array{
     *         id: int,
     *         name: string,
     *         brand: ?string,
     *         model: ?string,
     *         type: string,
     *         default_sport: ?string,
     *         initial_distance_meters: float,
     *         logged_distance_meters: float,
     *         total_distance_meters: float,
     *         max_distance_meters: ?float,
     *         usage_pct: ?float,
     *         alert_status: 'ok'|'warning'|'exceeded',
     *         activity_count: int,
     *         purchased_at: ?string,
     *         retired_at: ?string,
     *         is_retired: bool,
     *         notes: ?string,
     *         recent_activities: list<array{
     *             id: int,
     *             name: string,
     *             started_at_local: string,
     *             distance_meters: float,
     *             duration_seconds: float,
     *             sport_category: string,
     *             effective_discipline: ?string
     *         }>
     *     }>,
     *     retired: list<array{
     *         id: int,
     *         name: string,
     *         brand: ?string,
     *         model: ?string,
     *         type: string,
     *         default_sport: ?string,
     *         initial_distance_meters: float,
     *         logged_distance_meters: float,
     *         total_distance_meters: float,
     *         max_distance_meters: ?float,
     *         usage_pct: ?float,
     *         alert_status: 'ok'|'warning'|'exceeded',
     *         activity_count: int,
     *         purchased_at: ?string,
     *         retired_at: ?string,
     *         is_retired: bool,
     *         notes: ?string,
     *         recent_activities: list<array{
     *             id: int,
     *             name: string,
     *             started_at_local: string,
     *             distance_meters: float,
     *             duration_seconds: float,
     *             sport_category: string,
     *             effective_discipline: ?string
     *         }>
     *     }>,
     *     summary: array{
     *         active_count: int,
     *         retired_count: int,
     *         alerts_count: int,
     *         total_distance_meters: float
     *     }
     * }
     */
    public function list(): array
    {
        $items = GearItem::query()
            ->orderByRaw('CASE WHEN retired_at IS NULL THEN 0 ELSE 1 END ASC')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            return [
                'active' => [],
                'retired' => [],
                'summary' => [
                    'active_count' => 0,
                    'retired_count' => 0,
                    'alerts_count' => 0,
                    'total_distance_meters' => 0.0,
                ],
            ];
        }

        $aggregatesByGear = $this->loadAggregates();
        $recentByGear = $this->loadRecentActivities();

        $active = [];
        $retired = [];
        $alertsCount = 0;
        $totalDistanceMeters = 0.0;

        foreach ($items as $item) {
            $aggregate = $aggregatesByGear[$item->id] ?? [
                'activity_count' => 0,
                'logged_distance_meters' => 0.0,
            ];

            $initialDistance = round((float) $item->initial_distance_meters, 1);
            $loggedDistance = round($aggregate['logged_distance_meters'], 1);
            $totalDistance = round($initialDistance + $loggedDistance, 1);
            $maxDistance = $item->max_distance_meters !== null ? round((float) $item->max_distance_meters, 1) : null;

            $usagePct = null;

            if ($maxDistance !== null && $maxDistance > 0.0) {
                $usagePct = round(($totalDistance / $maxDistance) * 100.0, 1);
            }

            $alertStatus = $this->resolveAlertStatus($usagePct);
            $isRetired = $item->isRetired();

            $payload = [
                'id' => $item->id,
                'name' => $item->name,
                'brand' => $item->brand,
                'model' => $item->model,
                'type' => $item->type,
                'default_sport' => $item->default_sport,
                'initial_distance_meters' => $initialDistance,
                'logged_distance_meters' => $loggedDistance,
                'total_distance_meters' => $totalDistance,
                'max_distance_meters' => $maxDistance,
                'usage_pct' => $usagePct,
                'alert_status' => $alertStatus,
                'activity_count' => $aggregate['activity_count'],
                'purchased_at' => $item->purchased_at,
                'retired_at' => $item->retired_at,
                'is_retired' => $isRetired,
                'notes' => $item->notes,
                'recent_activities' => $recentByGear[$item->id] ?? [],
            ];

            $totalDistanceMeters += $totalDistance;

            if ($isRetired) {
                $retired[] = $payload;
            } else {
                if ($alertStatus !== 'ok') {
                    $alertsCount++;
                }

                $active[] = $payload;
            }
        }

        return [
            'active' => $active,
            'retired' => $retired,
            'summary' => [
                'active_count' => count($active),
                'retired_count' => count($retired),
                'alerts_count' => $alertsCount,
                'total_distance_meters' => round($totalDistanceMeters, 1),
            ],
        ];
    }

    /**
     * Auto-assign the active default gear for the activity's sport/discipline if none is assigned yet.
     */
    public function autoAssignDefaultGear(Activity $activity): void
    {
        $alreadyAssigned = DB::table('activity_gear')
            ->where('activity_id', $activity->id)
            ->exists();

        if ($alreadyAssigned) {
            return;
        }

        $candidates = $this->resolveDefaultSportCandidatesForActivity($activity);

        if ($candidates === []) {
            return;
        }

        $gearItem = null;

        foreach ($candidates as $candidate) {
            $gearItem = GearItem::query()
                ->active()
                ->where('default_sport', $candidate)
                ->orderBy('id')
                ->first();

            if ($gearItem !== null) {
                break;
            }
        }

        if ($gearItem === null) {
            return;
        }

        $now = now();

        DB::table('activity_gear')->insert([
            'activity_id' => $activity->id,
            'gear_item_id' => $gearItem->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Replace the assigned gear for an activity, or clear it when null.
     */
    public function assignToActivity(Activity $activity, ?int $gearItemId): void
    {
        DB::transaction(function () use ($activity, $gearItemId): void {
            DB::table('activity_gear')
                ->where('activity_id', $activity->id)
                ->delete();

            if ($gearItemId !== null) {
                $now = now();

                DB::table('activity_gear')->insert([
                    'activity_id' => $activity->id,
                    'gear_item_id' => $gearItemId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    /**
     * Build the gear picker payload for an activity detail view.
     *
     * @return array{
     *     assigned_id: ?int,
     *     assigned_name: ?string,
     *     available: list<array{
     *         id: int,
     *         name: string,
     *         type: string,
     *         default_sport: ?string,
     *         is_retired: bool
     *     }>
     * }
     */
    public function forActivity(Activity $activity): array
    {
        $assigned = DB::table('activity_gear')
            ->join('gear_items', 'gear_items.id', '=', 'activity_gear.gear_item_id')
            ->where('activity_gear.activity_id', $activity->id)
            ->select(['gear_items.id', 'gear_items.name'])
            ->orderBy('activity_gear.id')
            ->first();

        $availableItems = GearItem::query()
            ->orderByRaw('CASE WHEN retired_at IS NULL THEN 0 ELSE 1 END ASC')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'type', 'default_sport', 'retired_at']);

        $available = [];

        foreach ($availableItems as $item) {
            $available[] = [
                'id' => $item->id,
                'name' => $item->name,
                'type' => $item->type,
                'default_sport' => $item->default_sport,
                'is_retired' => $item->isRetired(),
            ];
        }

        return [
            'assigned_id' => $assigned !== null ? (int) $assigned->id : null,
            'assigned_name' => $assigned !== null ? (string) $assigned->name : null,
            'available' => $available,
        ];
    }

    /**
     * @return array<int, array{activity_count: int, logged_distance_meters: float}>
     */
    private function loadAggregates(): array
    {
        $rows = DB::table('activity_gear')
            ->join('activities', 'activities.id', '=', 'activity_gear.activity_id')
            ->whereNull('activities.excluded_at')
            ->groupBy('activity_gear.gear_item_id')
            ->selectRaw('activity_gear.gear_item_id as gear_item_id')
            ->selectRaw('COUNT(activities.id) as activity_count')
            ->selectRaw('COALESCE(SUM(activities.distance_meters), 0) as logged_distance_meters')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->gear_item_id] = [
                'activity_count' => (int) $row->activity_count,
                'logged_distance_meters' => (float) $row->logged_distance_meters,
            ];
        }

        return $result;
    }

    /**
     * @return array<int, list<array{
     *     id: int,
     *     name: string,
     *     started_at_local: string,
     *     distance_meters: float,
     *     duration_seconds: float,
     *     sport_category: string,
     *     effective_discipline: ?string
     * }>>
     */
    private function loadRecentActivities(): array
    {
        $rankedSubquery = DB::table('activity_gear')
            ->join('activities', 'activities.id', '=', 'activity_gear.activity_id')
            ->whereNull('activities.excluded_at')
            ->select([
                'activity_gear.gear_item_id',
                'activities.id',
                'activities.name',
                'activities.started_at_local',
                'activities.distance_meters',
                'activities.duration_seconds',
                'activities.sport_category',
                'activities.sport_discipline',
                'activities.discipline_override',
            ])
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY activity_gear.gear_item_id ORDER BY activities.started_at_local DESC, activities.id DESC) as rn',
            );

        $rows = DB::query()
            ->fromSub($rankedSubquery, 'ranked_activities')
            ->where('rn', '<=', self::RECENT_ACTIVITIES_LIMIT)
            ->orderBy('gear_item_id')
            ->orderBy('rn')
            ->get();

        $byGear = [];

        foreach ($rows as $row) {
            $gearId = (int) $row->gear_item_id;
            $disciplineOverride = is_string($row->discipline_override) ? $row->discipline_override : null;
            $sportDiscipline = is_string($row->sport_discipline) ? $row->sport_discipline : null;

            $byGear[$gearId][] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'started_at_local' => (string) $row->started_at_local,
                'distance_meters' => round((float) $row->distance_meters, 1),
                'duration_seconds' => round((float) $row->duration_seconds, 1),
                'sport_category' => (string) $row->sport_category,
                'effective_discipline' => $disciplineOverride ?? $sportDiscipline,
            ];
        }

        return $byGear;
    }

    /**
     * @return 'ok'|'warning'|'exceeded'
     */
    private function resolveAlertStatus(?float $usagePct): string
    {
        if ($usagePct === null) {
            return 'ok';
        }

        if ($usagePct >= self::EXCEEDED_THRESHOLD_PCT) {
            return 'exceeded';
        }

        if ($usagePct >= self::WARNING_THRESHOLD_PCT) {
            return 'warning';
        }

        return 'ok';
    }

    /**
     * Match specific discipline first (including legacy aliases), then fall back to parent sport.
     *
     * @return list<string>
     */
    private function resolveDefaultSportCandidatesForActivity(Activity $activity): array
    {
        $discipline = $activity->effectiveDiscipline();

        if ($activity->sport_category === 'running') {
            if ($activity->is_indoor) {
                return ['treadmill_running', 'road_running', 'running'];
            }

            return match ($discipline) {
                'trail' => ['trail_running', 'running'],
                'track' => ['track_running', 'road_running', 'running'],
                default => ['road_running', 'running'],
            };
        }

        if ($activity->sport_category === 'biking') {
            if ($activity->is_indoor) {
                return ['indoor_cycling', 'road_cycling', 'road_biking', 'cycling', 'biking'];
            }

            return match ($discipline) {
                'road' => ['road_cycling', 'road_biking', 'cycling', 'biking'],
                'gravel' => ['gravel_cycling', 'cycling', 'biking'],
                'mtb' => ['mtb_cycling', 'mtb_biking', 'cycling', 'biking'],
                'ebike' => ['ebike_cycling', 'cycling', 'biking'],
                'commute' => ['commute_cycling', 'cycling', 'biking'],
                default => ['cycling', 'biking'],
            };
        }

        return match ($activity->sport_category) {
            'hiking', 'walking', 'swimming', 'cardio' => [$activity->sport_category],
            default => [],
        };
    }
}
