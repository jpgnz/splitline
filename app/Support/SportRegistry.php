<?php

namespace App\Support;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central Sports & Disciplines registry merging static definitions in `config/sports.php`
 * with user preferences persisted in `app_settings` and zero-config auto-detection.
 *
 * @phpstan-type SportCatalogEntry array{
 *     key: string,
 *     parent: ?string,
 *     sport_category: string,
 *     discipline: ?string,
 *     indoor_only: bool,
 *     label: string,
 *     short_label: string,
 *     slug: string,
 *     href: string,
 *     icon: string,
 *     color_token: string,
 *     accent_hex: string,
 *     metric_mode: 'pace'|'speed'|'pace_100m'|'duration',
 *     cadence_unit: 'spm'|'rpm'|'strokes/min'|null,
 *     hub_type: 'overview'|'endurance'|'general',
 *     default_modules: list<string>,
 *     best_effort_profile: 'running'|'cycling'|null,
 *     disciplines?: list<string>
 * }
 * @phpstan-type ResolvedSportsConfig array{
 *     mode: 'auto'|'custom',
 *     pin_sub_disciplines: bool,
 *     order: list<string>,
 *     families: array{
 *         running: array{mode: 'off'|'unified'|'split', disciplines: list<string>},
 *         cycling: array{mode: 'off'|'unified'|'split', disciplines: list<string>}
 *     },
 *     secondary: list<string>,
 *     rollups: array{
 *         trail_running: 'own'|'running',
 *         track_running: 'road_running'|'own',
 *         gravel_cycling: 'own'|'road_cycling'|'mtb_cycling',
 *         indoor_cycling: 'road_cycling'|'own',
 *         ebike_cycling: 'own'|'mtb_cycling'|'exclude'
 *     },
 *     best_efforts: array{
 *         trail_in_running_prs: bool,
 *         cycling_disciplines: list<string>
 *     }
 * }
 */
class SportRegistry
{
    public const string SETTINGS_KEY = 'sports.config';

    private const array LEGACY_ALIASES = [
        'biking' => 'cycling',
        'road_biking' => 'road_cycling',
        'mtb_biking' => 'mtb_cycling',
        'road' => 'road_cycling',
        'mtb' => 'mtb_cycling',
        'gravel' => 'gravel_cycling',
        'ebike' => 'ebike_cycling',
        'commute' => 'commute_cycling',
    ];

    private const array SECONDARY_KEYS = ['hiking', 'walking', 'swimming', 'cardio'];

    private const array DEFAULT_ORDER = ['running', 'cycling', 'hiking', 'walking', 'swimming', 'cardio'];

    /**
     * Generic sport keys ignored during dominant-discipline resolution whenever specific disciplines exist.
     *
     * @var list<string>
     */
    private const array GENERIC_SPORT_KEYS = ['cycling', 'other'];

    /** @var ResolvedSportsConfig|null */
    private ?array $resolvedConfig = null;

    public function __construct(private readonly AppSettings $settings) {}

    public function flushMemo(): void
    {
        $this->resolvedConfig = null;
    }

    /**
     * @return array<string, SportCatalogEntry>
     */
    public function catalog(): array
    {
        /** @var array<string, SportCatalogEntry> $catalog */
        $catalog = config('sports.catalog', []);

        return $catalog;
    }

    /**
     * @return SportCatalogEntry|null
     */
    public function get(string $key): ?array
    {
        $normalized = $this->normalizeKey($key);
        $catalog = $this->catalog();

        return $catalog[$normalized] ?? null;
    }

    public function normalizeKey(string $key): string
    {
        $lower = strtolower(trim($key));

        return self::LEGACY_ALIASES[$lower] ?? $lower;
    }

    /**
     * Resolve the active sports configuration (either auto-detected or user-customized).
     *
     * @return ResolvedSportsConfig
     */
    public function resolvedConfig(): array
    {
        if ($this->resolvedConfig !== null) {
            return $this->resolvedConfig;
        }

        $stored = $this->settings->get(self::SETTINGS_KEY);
        $storedArray = is_array($stored) ? $stored : [];

        $defaultMode = strtolower((string) config('app.default_sports_mode', 'auto')) === 'custom'
            ? 'custom'
            : 'auto';
        $mode = ($storedArray['mode'] ?? $defaultMode) === 'custom' ? 'custom' : 'auto';

        if ($mode === 'auto') {
            $auto = $this->autoDetectConfig();
            $auto['pin_sub_disciplines'] = (bool) ($storedArray['pin_sub_disciplines'] ?? false);

            return $this->resolvedConfig = $auto;
        }

        return $this->resolvedConfig = $this->normalizeCustomConfig($storedArray);
    }

    /**
     * Persist a custom or auto sports configuration.
     *
     * @param  array<string, mixed>  $input
     * @return ResolvedSportsConfig
     */
    public function saveConfig(array $input): array
    {
        $mode = ($input['mode'] ?? 'custom') === 'auto' ? 'auto' : 'custom';

        if ($mode === 'auto') {
            $this->settings->set(self::SETTINGS_KEY, [
                'mode' => 'auto',
                'pin_sub_disciplines' => (bool) ($input['pin_sub_disciplines'] ?? false),
            ]);
            $this->flushMemo();

            return $this->resolvedConfig();
        }

        $normalized = $this->normalizeCustomConfig($input);
        $this->settings->set(self::SETTINGS_KEY, $normalized);
        $this->flushMemo();

        return $this->resolvedConfig();
    }

    /**
     * Build navigation items for the sidebar rail.
     *
     * @return list<array{
     *     key: string,
     *     label: string,
     *     short_label: string,
     *     href: string,
     *     icon: string,
     *     color_token: string,
     *     accent_hex: string,
     *     parent: ?string,
     *     children?: list<array{
     *         key: string,
     *         label: string,
     *         short_label: string,
     *         href: string,
     *         icon: string,
     *         color_token: string,
     *         accent_hex: string,
     *         parent: ?string
     *     }>
     * }>
     */
    public function navigationItems(): array
    {
        $config = $this->resolvedConfig();
        $catalog = $this->catalog();
        $items = [];

        foreach ($config['order'] as $topKey) {
            if ($topKey === 'running' || $topKey === 'cycling') {
                $family = $config['families'][$topKey];

                if ($family['mode'] === 'off') {
                    continue;
                }

                $entry = $catalog[$topKey] ?? null;

                if ($entry === null) {
                    continue;
                }

                $navItem = $this->toNavItem($entry);

                if ($family['mode'] === 'split' && $family['disciplines'] !== []) {
                    $children = [];

                    foreach ($family['disciplines'] as $childKey) {
                        if (isset($catalog[$childKey])) {
                            $children[] = $this->toNavItem($catalog[$childKey]);
                        }
                    }

                    if ($children !== []) {
                        $navItem['children'] = $children;
                    }
                }

                $items[] = $navItem;

                continue;
            }

            if (in_array($topKey, $config['secondary'], true) && isset($catalog[$topKey])) {
                $items[] = $this->toNavItem($catalog[$topKey]);
            }
        }

        return $items;
    }

    /**
     * Enabled sub-discipline tab items for a family header (`running` or `cycling`).
     *
     * @return list<array{key: string, label: string, short_label: string, href: string, slug: string}>
     */
    public function familyTabs(string $familyKey): array
    {
        $config = $this->resolvedConfig();

        if (! isset($config['families'][$familyKey])) {
            return [];
        }

        $family = $config['families'][$familyKey];

        if ($family['mode'] !== 'split') {
            return [];
        }

        $catalog = $this->catalog();
        $parent = $catalog[$familyKey] ?? null;

        if ($parent === null) {
            return [];
        }

        $tabs = [[
            'key' => $familyKey,
            'label' => 'Overview',
            'short_label' => 'Overview',
            'href' => $parent['href'],
            'slug' => 'overview',
        ]];

        foreach ($family['disciplines'] as $disciplineKey) {
            $entry = $catalog[$disciplineKey] ?? null;

            if ($entry === null) {
                continue;
            }

            $tabs[] = [
                'key' => $entry['key'],
                'label' => $entry['label'],
                'short_label' => $entry['short_label'],
                'href' => $entry['href'],
                'slug' => $entry['slug'],
            ];
        }

        return $tabs;
    }

    /**
     * Shared payload sent via `HandleInertiaRequests` (`page.props.sports`).
     *
     * @return array<string, mixed>
     */
    public function sharedFrontendPayload(): array
    {
        $config = $this->resolvedConfig();
        $catalog = [];

        foreach ($this->catalog() as $key => $entry) {
            $catalog[$key] = [
                'key' => $entry['key'],
                'parent' => $entry['parent'],
                'label' => $entry['label'],
                'short_label' => $entry['short_label'],
                'slug' => $entry['slug'],
                'icon' => $entry['icon'],
                'color_token' => $entry['color_token'],
                'accent_hex' => $entry['accent_hex'],
                'metric_mode' => $entry['metric_mode'],
                'cadence_unit' => $entry['cadence_unit'],
                'hub_type' => $entry['hub_type'],
            ];
        }

        return [
            'mode' => $config['mode'],
            'pin_sub_disciplines' => $config['pin_sub_disciplines'],
            'nav' => $this->navigationItems(),
            'catalog' => $catalog,
        ];
    }

    /**
     * Settings page payload including activity counts and discovery hints.
     *
     * @return array<string, mixed>
     */
    public function settingsPayload(): array
    {
        $config = $this->resolvedConfig();
        $counts = $this->activityCountsByBucket();
        $hints = [];

        $checkHints = [
            'trail_running' => [
                'enabled' => $config['families']['running']['mode'] === 'split'
                    && in_array('trail_running', $config['families']['running']['disciplines'], true),
                'count' => $counts['trail_running'],
                'label' => 'Trail Running',
            ],
            'track_running' => [
                'enabled' => $config['families']['running']['mode'] === 'split'
                    && in_array('track_running', $config['families']['running']['disciplines'], true),
                'count' => $counts['track_running'],
                'label' => 'Track Running',
            ],
            'gravel_cycling' => [
                'enabled' => $config['families']['cycling']['mode'] === 'split'
                    && in_array('gravel_cycling', $config['families']['cycling']['disciplines'], true),
                'count' => $counts['gravel_cycling'],
                'label' => 'Gravel / CX',
            ],
            'mtb_cycling' => [
                'enabled' => $config['families']['cycling']['mode'] === 'split'
                    && in_array('mtb_cycling', $config['families']['cycling']['disciplines'], true),
                'count' => $counts['mtb_cycling'],
                'label' => 'Mountain Biking',
            ],
            'ebike_cycling' => [
                'enabled' => $config['families']['cycling']['mode'] === 'split'
                    && in_array('ebike_cycling', $config['families']['cycling']['disciplines'], true),
                'count' => $counts['ebike_cycling'],
                'label' => 'E-Bike',
            ],
            'hiking' => [
                'enabled' => in_array('hiking', $config['secondary'], true),
                'count' => $counts['hiking'],
                'label' => 'Hiking',
            ],
            'walking' => [
                'enabled' => in_array('walking', $config['secondary'], true),
                'count' => $counts['walking'],
                'label' => 'Walking',
            ],
            'swimming' => [
                'enabled' => in_array('swimming', $config['secondary'], true),
                'count' => $counts['swimming'],
                'label' => 'Swimming',
            ],
            'cardio' => [
                'enabled' => in_array('cardio', $config['secondary'], true),
                'count' => $counts['cardio'],
                'label' => 'Strength & Cardio',
            ],
        ];

        foreach ($checkHints as $key => $hint) {
            if (! $hint['enabled'] && $hint['count'] > 0) {
                $hints[] = [
                    'key' => $key,
                    'label' => $hint['label'],
                    'count' => $hint['count'],
                ];
            }
        }

        return [
            'config' => $config,
            'counts' => $counts,
            'hints' => $hints,
        ];
    }

    /**
     * Resolve enabled sport keys for Dashboard breakdown, Goals, Heatmap, and Streaks.
     *
     * @return list<string>
     */
    public function activeSportKeys(): array
    {
        $config = $this->resolvedConfig();
        $keys = [];

        foreach ($config['order'] as $topKey) {
            if ($topKey === 'running' || $topKey === 'cycling') {
                $family = $config['families'][$topKey];

                if ($family['mode'] === 'unified') {
                    $keys[] = $topKey;
                } elseif ($family['mode'] === 'split') {
                    foreach ($family['disciplines'] as $disciplineKey) {
                        $keys[] = $disciplineKey;
                    }
                }

                continue;
            }

            if (in_array($topKey, $config['secondary'], true)) {
                $keys[] = $topKey;
            }
        }

        return $keys !== [] ? $keys : ['running', 'cycling'];
    }

    /**
     * Return all sport keys that should appear in activity filter pills (enabled sports + any with recorded activities).
     *
     * @return list<array{key: string, label: string, short_label: string, accent_hex: string}>
     */
    public function filterableSports(): array
    {
        $keys = $this->activeSportKeys();
        $counts = $this->activityCountsByBucket();

        foreach ($counts as $bucketKey => $count) {
            if ($count > 0 && ! in_array($bucketKey, $keys, true)) {
                $keys[] = $bucketKey;
            }
        }

        $catalog = $this->catalog();
        $result = [];
        $seen = [];

        foreach ($keys as $key) {
            $normalized = $this->normalizeKey($key);

            if (isset($seen[$normalized]) || ! isset($catalog[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $entry = $catalog[$normalized];
            $result[] = [
                'key' => $entry['key'],
                'label' => $entry['label'],
                'short_label' => $entry['short_label'],
                'accent_hex' => $entry['accent_hex'],
            ];
        }

        return $result;
    }

    /**
     * Apply authoritative sport, discipline, rollup, environment, and terrain filters to an Activity query.
     *
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function applyScope(
        Builder $query,
        string $sportKey,
        ?string $environment = null,
        ?string $terrain = null,
    ): Builder {
        $key = $this->normalizeKey($sportKey);
        $config = $this->resolvedConfig();
        $rollups = $config['rollups'];

        if ($key === 'all') {
            return $this->applyEnvironmentAndTerrain($query, $environment, $terrain);
        }

        $effectiveExpr = 'COALESCE(discipline_override, sport_discipline)';

        match ($key) {
            'running' => $query->where('sport_category', 'running'),
            'road_running' => $query->where('sport_category', 'running')->where(function (Builder $sub) use ($effectiveExpr, $rollups): void {
                $disciplines = ['road'];

                if ($rollups['track_running'] === 'road_running') {
                    $disciplines[] = 'track';
                }

                if ($rollups['trail_running'] === 'running') {
                    $disciplines[] = 'trail';
                }

                $placeholders = implode(', ', array_fill(0, count($disciplines), '?'));
                $sub->whereRaw("{$effectiveExpr} IN ({$placeholders})", $disciplines)
                    ->orWhereRaw("{$effectiveExpr} IS NULL");
            }),
            'trail_running' => $query->where('sport_category', 'running')
                ->whereRaw("{$effectiveExpr} = ?", ['trail']),
            'track_running' => $query->where('sport_category', 'running')
                ->whereRaw("{$effectiveExpr} = ?", ['track']),
            'treadmill_running' => $query->where('sport_category', 'running')
                ->where('is_indoor', true),
            'cycling' => $query->where('sport_category', 'biking')->when(
                $rollups['ebike_cycling'] === 'exclude',
                fn (Builder $q): Builder => $q->whereRaw("COALESCE({$effectiveExpr}, '') != ?", ['ebike']),
            ),
            'road_cycling' => $query->where('sport_category', 'biking')->where(function (Builder $sub) use ($effectiveExpr, $rollups): void {
                $disciplines = ['road'];

                if ($rollups['gravel_cycling'] === 'road_cycling') {
                    $disciplines[] = 'gravel';
                }

                $placeholders = implode(', ', array_fill(0, count($disciplines), '?'));
                $sub->whereRaw("{$effectiveExpr} IN ({$placeholders})", $disciplines);
            })->when(
                $rollups['indoor_cycling'] === 'own',
                fn (Builder $q): Builder => $q->where('is_indoor', false),
            ),
            'gravel_cycling' => $query->where('sport_category', 'biking')
                ->whereRaw("{$effectiveExpr} = ?", ['gravel']),
            'mtb_cycling' => $query->where('sport_category', 'biking')->where(function (Builder $sub) use ($effectiveExpr, $rollups): void {
                $disciplines = ['mtb'];

                if ($rollups['gravel_cycling'] === 'mtb_cycling') {
                    $disciplines[] = 'gravel';
                }

                if ($rollups['ebike_cycling'] === 'mtb_cycling') {
                    $disciplines[] = 'ebike';
                }

                $placeholders = implode(', ', array_fill(0, count($disciplines), '?'));
                $sub->whereRaw("{$effectiveExpr} IN ({$placeholders})", $disciplines);
            }),
            'indoor_cycling' => $query->where('sport_category', 'biking')
                ->where('is_indoor', true),
            'ebike_cycling' => $query->where('sport_category', 'biking')
                ->whereRaw("{$effectiveExpr} = ?", ['ebike']),
            'commute_cycling' => $query->where('sport_category', 'biking')
                ->whereRaw("{$effectiveExpr} = ?", ['commute']),
            'other_cycling' => $query->where('sport_category', 'biking')
                ->where(function (Builder $sub) use ($effectiveExpr): void {
                    $sub->whereRaw("{$effectiveExpr} = ?", ['other'])
                        ->orWhereRaw("{$effectiveExpr} IS NULL");
                }),
            'other_running' => $query->where('sport_category', 'running')
                ->whereRaw("{$effectiveExpr} = ?", ['other']),
            'hiking', 'walking', 'swimming', 'cardio', 'other' => $query->where('sport_category', $key),
            default => $query->where('sport_category', $key),
        };

        return $this->applyEnvironmentAndTerrain($query, $environment, $terrain);
    }

    /**
     * Resolve the canonical sport key for an Activity instance (used for badges, gear matching, and summaries).
     */
    public function resolveActivitySportKey(Activity $activity): string
    {
        return $this->resolveSportKey(
            $activity->sport_category,
            $activity->effectiveDiscipline(),
            (bool) $activity->is_indoor,
        );
    }

    /**
     * Resolve the canonical sport key from raw activity sport attributes.
     */
    public function resolveSportKey(string $sportCategory, ?string $effectiveDiscipline, bool $isIndoor = false): string
    {
        if ($sportCategory === 'running') {
            if ($isIndoor) {
                return 'treadmill_running';
            }

            return match ($effectiveDiscipline) {
                'trail' => 'trail_running',
                'track' => 'track_running',
                default => 'road_running',
            };
        }

        if ($sportCategory === 'biking') {
            if ($isIndoor && $this->resolvedConfig()['rollups']['indoor_cycling'] === 'own') {
                return 'indoor_cycling';
            }

            return match ($effectiveDiscipline) {
                'road' => 'road_cycling',
                'gravel' => 'gravel_cycling',
                'mtb' => 'mtb_cycling',
                'ebike' => 'ebike_cycling',
                'commute' => 'commute_cycling',
                default => 'cycling',
            };
        }

        return match ($sportCategory) {
            'hiking', 'walking', 'swimming', 'cardio' => $sportCategory,
            default => 'other',
        };
    }

    /**
     * Map a resolved sport key onto the bucket it rolls up into under the active rollup settings
     * (e.g. `gravel_cycling` becomes `road_cycling` when gravel is rolled into road), mirroring `applyScope()`.
     */
    public function rollUpSportKey(string $sportKey): string
    {
        $key = $this->normalizeKey($sportKey);
        $rollups = $this->resolvedConfig()['rollups'];

        return match ($key) {
            'trail_running' => $rollups['trail_running'] === 'running' ? 'road_running' : $key,
            'track_running' => $rollups['track_running'] === 'road_running' ? 'road_running' : $key,
            'gravel_cycling' => $rollups['gravel_cycling'] === 'own' ? $key : $rollups['gravel_cycling'],
            'ebike_cycling' => $rollups['ebike_cycling'] === 'mtb_cycling' ? 'mtb_cycling' : $key,
            default => $key,
        };
    }

    /**
     * Determine whether two resolved sport keys fall into the same rolled-up bucket.
     */
    public function sportKeysShareBucket(string $sportKey, string $otherSportKey): bool
    {
        return $this->rollUpSportKey($sportKey) === $this->rollUpSportKey($otherSportKey);
    }

    /**
     * Determine whether a resolved canonical sport key matches a filter scope key, respecting rollups and parents.
     */
    public function sportKeyMatchesScope(string $sportKey, string $scopeKey): bool
    {
        $key = $this->normalizeKey($sportKey);
        $scope = $this->normalizeKey($scopeKey);

        if ($scope === 'all' || $key === $scope || $this->rollUpSportKey($key) === $scope) {
            return true;
        }

        if ($key === 'ebike_cycling' && $this->resolvedConfig()['rollups']['ebike_cycling'] === 'exclude') {
            return false;
        }

        return ($this->get($key)['parent'] ?? null) === $scope;
    }

    /**
     * Add occurrences of a resolved sport key to a running tally, keeping the latest timestamp seen.
     *
     * @param  array<string, array{count: int, latest_at: string}>  $sportCounts
     *
     * @param-out array<string, array{count: int, latest_at: string}>  $sportCounts
     */
    public function addSportKeyCount(array &$sportCounts, string $sportKey, string $latestAt, int $count = 1): void
    {
        if (! isset($sportCounts[$sportKey])) {
            $sportCounts[$sportKey] = [
                'count' => $count,
                'latest_at' => $latestAt,
            ];

            return;
        }

        $sportCounts[$sportKey]['count'] += $count;

        if ($latestAt > $sportCounts[$sportKey]['latest_at']) {
            $sportCounts[$sportKey]['latest_at'] = $latestAt;
        }
    }

    /**
     * Pick the canonical sport key for a multi-activity entity (Segment, Matched Route, etc.)
     * from its activities' resolved sport-key counts.
     *
     * Prefers specific disciplines over generic fallbacks (`cycling`, `other`) whenever specific
     * disciplines exist, then picks the rolled-up bucket with the most activities. A bucket holding
     * several keys resolves to the bucket key; a single-key bucket keeps that key so it stays specific.
     * Ties break on `$fallbackSportKey`, then most recent activity, then key.
     *
     * @param  array<string, array{count: int, latest_at: string}>  $sportCounts
     */
    public function resolveDominantSportKey(
        array $sportCounts,
        ?string $fallbackSportKey,
        string $sportCategory,
    ): string {
        if ($sportCounts === []) {
            return $fallbackSportKey ?? $this->normalizeKey($sportCategory);
        }

        $specificCounts = array_filter(
            $sportCounts,
            static fn (string $key): bool => ! in_array($key, self::GENERIC_SPORT_KEYS, true),
            ARRAY_FILTER_USE_KEY,
        );

        $buckets = $this->groupSportCountsByBucket($specificCounts !== [] ? $specificCounts : $sportCounts);
        $winningBucket = $this->pickTopSportKey(
            $buckets,
            $fallbackSportKey !== null ? $this->rollUpSportKey($fallbackSportKey) : null,
        );

        return $this->bucketDisplayKey($winningBucket, $buckets[$winningBucket]['members']);
    }

    /**
     * Build ordered sport/discipline filter pills with counts for a multi-activity detail page.
     *
     * Keys that roll up into the same bucket share a pill keyed by the bucket; a bucket with a
     * single member keeps that member's key so its label stays specific.
     *
     * @param  array<string, array{count: int, latest_at: string}>  $sportCounts
     * @return list<array{key: string, label: string, short_label: string, accent_hex: string, count: int}>
     */
    public function buildSportFilterOptions(array $sportCounts): array
    {
        $options = [];

        foreach ($this->groupSportCountsByBucket($sportCounts) as $bucketKey => $bucket) {
            $optionKey = $this->bucketDisplayKey($bucketKey, $bucket['members']);
            $entry = $this->get($optionKey);
            $fallbackLabel = ucfirst(str_replace('_', ' ', $optionKey));

            $options[] = [
                'key' => $entry['key'] ?? $optionKey,
                'label' => $entry['label'] ?? $fallbackLabel,
                'short_label' => $entry['short_label'] ?? $fallbackLabel,
                'accent_hex' => $entry['accent_hex'] ?? '#f59e0b',
                'count' => $bucket['count'],
            ];
        }

        usort($options, static function (array $a, array $b): int {
            $countCmp = $b['count'] <=> $a['count'];

            if ($countCmp !== 0) {
                return $countCmp;
            }

            return $a['key'] <=> $b['key'];
        });

        return $options;
    }

    /**
     * Resolve a requested detail-page sport filter against the available filter options, ignoring unknown keys.
     *
     * @param  list<array{key: string, label: string, short_label: string, accent_hex: string, count: int}>  $options
     */
    public function resolveSportFilterSelection(array $options, ?string $sport): ?string
    {
        if ($sport === null) {
            return null;
        }

        $normalized = $this->normalizeKey($sport);

        foreach ($options as $option) {
            if ($option['key'] === $normalized) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Determine which Best Effort sport key (`running`, `road_biking`, etc.) an activity qualifies for.
     */
    public function resolveBestEffortSport(Activity $activity): ?string
    {
        if ($activity->is_indoor) {
            return null;
        }

        $config = $this->resolvedConfig();
        $discipline = $activity->effectiveDiscipline();

        if ($activity->sport_category === 'running') {
            if ($discipline === 'trail' && ! $config['best_efforts']['trail_in_running_prs']) {
                return 'trail_running';
            }

            return 'running';
        }

        if ($activity->sport_category === 'biking') {
            $allowed = $config['best_efforts']['cycling_disciplines'];
            $effective = $discipline ?? 'other';

            if (! in_array($effective, $allowed, true)) {
                return null;
            }

            return match ($effective) {
                'road' => 'road_biking',
                'gravel' => 'gravel_cycling',
                'mtb' => 'mtb_cycling',
                'ebike' => 'ebike_cycling',
                default => null,
            };
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function validGoalSports(): array
    {
        return array_values(array_unique([
            'all',
            ...array_keys($this->catalog()),
            'biking',
            'road_biking',
            'mtb_biking',
        ]));
    }

    /**
     * @return list<string>
     */
    public function validGearTypes(): array
    {
        /** @var array<string, string> $types */
        $types = config('sports.gear_types', []);

        return array_keys($types);
    }

    /**
     * @return list<string>
     */
    public function validGearDefaultSports(): array
    {
        return array_values(array_unique([
            ...array_keys($this->catalog()),
            'biking',
            'road_biking',
            'mtb_biking',
        ]));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function disciplinesForCategory(string $sportCategory): array
    {
        /** @var array<string, list<array{value: string, label: string}>> $map */
        $map = config('sports.category_disciplines', []);

        return $map[$sportCategory] ?? [];
    }

    /**
     * @return list<string>
     */
    public function validDisciplineValuesForCategory(string $sportCategory): array
    {
        return array_map(
            static fn (array $item): string => $item['value'],
            $this->disciplinesForCategory($sportCategory),
        );
    }

    /**
     * Group resolved sport-key counts by the bucket each key rolls up into.
     *
     * @param  array<string, array{count: int, latest_at: string}>  $sportCounts
     * @return ($sportCounts is non-empty-array
     *     ? non-empty-array<string, array{count: int, latest_at: string, members: non-empty-array<string, array{count: int, latest_at: string}>}>
     *     : array<string, array{count: int, latest_at: string, members: non-empty-array<string, array{count: int, latest_at: string}>}>)
     */
    private function groupSportCountsByBucket(array $sportCounts): array
    {
        $buckets = [];

        foreach ($sportCounts as $sportKey => $stats) {
            $bucketKey = $this->rollUpSportKey($sportKey);

            if (! isset($buckets[$bucketKey])) {
                $buckets[$bucketKey] = [
                    'count' => 0,
                    'latest_at' => '',
                    'members' => [],
                ];
            }

            $buckets[$bucketKey]['count'] += $stats['count'];
            $buckets[$bucketKey]['members'][$sportKey] = $stats;

            if ($stats['latest_at'] > $buckets[$bucketKey]['latest_at']) {
                $buckets[$bucketKey]['latest_at'] = $stats['latest_at'];
            }
        }

        return $buckets;
    }

    /**
     * Resolve the key shown for a rolled-up bucket: its single member when only one key rolled into it.
     *
     * @param  array<string, array{count: int, latest_at: string}>  $members
     */
    private function bucketDisplayKey(string $bucketKey, array $members): string
    {
        return count($members) === 1 ? (string) array_key_first($members) : $bucketKey;
    }

    /**
     * Pick the key with the highest count, breaking ties on the preferred key, then most recent, then key.
     *
     * @param  non-empty-array<string, array{count: int, latest_at: string}>  $pool
     */
    private function pickTopSportKey(array $pool, ?string $preferredKey): string
    {
        $maxCount = max(array_column($pool, 'count'));
        $topKeys = array_keys(array_filter(
            $pool,
            static fn (array $stats): bool => $stats['count'] === $maxCount,
        ));

        if (count($topKeys) === 1) {
            return (string) $topKeys[0];
        }

        if ($preferredKey !== null && in_array($preferredKey, $topKeys, true)) {
            return $preferredKey;
        }

        usort($topKeys, static function (string|int $a, string|int $b) use ($pool): int {
            $latestCmp = $pool[$b]['latest_at'] <=> $pool[$a]['latest_at'];

            if ($latestCmp !== 0) {
                return $latestCmp;
            }

            return (string) $a <=> (string) $b;
        });

        return (string) $topKeys[0];
    }

    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    private function applyEnvironmentAndTerrain(Builder $query, ?string $environment, ?string $terrain): Builder
    {
        if ($environment === 'outdoor') {
            $query->where('is_indoor', false);
        } elseif ($environment === 'indoor') {
            $query->where('is_indoor', true);
        }

        $flatMax = (float) config('sports.terrain_buckets.flat_max_m_per_km', 10.0);
        $rollingMax = (float) config('sports.terrain_buckets.rolling_max_m_per_km', 25.0);

        if ($terrain === 'flat') {
            $query->where('distance_meters', '>', 0)
                ->whereRaw('(COALESCE(elevation_gain, 0) / (distance_meters / 1000.0)) < (? + 0.0)', [$flatMax]);
        } elseif ($terrain === 'rolling') {
            $query->where('distance_meters', '>', 0)
                ->whereRaw('(COALESCE(elevation_gain, 0) / (distance_meters / 1000.0)) >= (? + 0.0)', [$flatMax])
                ->whereRaw('(COALESCE(elevation_gain, 0) / (distance_meters / 1000.0)) < (? + 0.0)', [$rollingMax]);
        } elseif ($terrain === 'steep') {
            $query->where('distance_meters', '>', 0)
                ->whereRaw('(COALESCE(elevation_gain, 0) / (distance_meters / 1000.0)) >= (? + 0.0)', [$rollingMax]);
        }

        return $query;
    }

    /**
     * @return ResolvedSportsConfig
     */
    private function autoDetectConfig(): array
    {
        $counts = $this->activityCountsByBucket();
        $totalActivities = array_sum($counts);

        if ($totalActivities === 0) {
            return [
                'mode' => 'auto',
                'pin_sub_disciplines' => false,
                'order' => self::DEFAULT_ORDER,
                'families' => [
                    'running' => ['mode' => 'unified', 'disciplines' => ['road_running']],
                    'cycling' => ['mode' => 'unified', 'disciplines' => ['road_cycling']],
                ],
                'secondary' => [],
                'rollups' => [
                    'trail_running' => 'running',
                    'track_running' => 'road_running',
                    'gravel_cycling' => 'own',
                    'indoor_cycling' => 'road_cycling',
                    'ebike_cycling' => 'own',
                ],
                'best_efforts' => [
                    'trail_in_running_prs' => true,
                    'cycling_disciplines' => ['road'],
                ],
            ];
        }

        // Running detection
        $runDisciplines = [];
        if ($counts['road_running'] > 0) {
            $runDisciplines[] = 'road_running';
        }
        if ($counts['trail_running'] > 0) {
            $runDisciplines[] = 'trail_running';
        }
        if ($counts['track_running'] > 0) {
            $runDisciplines[] = 'track_running';
        }

        $totalRunning = $counts['road_running'] + $counts['trail_running'] + $counts['track_running'] + $counts['treadmill_running'];
        if ($totalRunning === 0) {
            $runningFamily = ['mode' => 'off', 'disciplines' => []];
        } elseif (count($runDisciplines) >= 2) {
            $runningFamily = ['mode' => 'split', 'disciplines' => $runDisciplines];
        } else {
            $runningFamily = ['mode' => 'unified', 'disciplines' => $runDisciplines !== [] ? $runDisciplines : ['road_running']];
        }

        // Cycling detection
        $cycleDisciplines = [];
        foreach (['road_cycling', 'gravel_cycling', 'mtb_cycling', 'ebike_cycling', 'commute_cycling'] as $cycleKey) {
            if ($counts[$cycleKey] > 0) {
                $cycleDisciplines[] = $cycleKey;
            }
        }

        $totalCycling = $counts['road_cycling'] + $counts['gravel_cycling'] + $counts['mtb_cycling']
            + $counts['indoor_cycling'] + $counts['ebike_cycling'] + $counts['commute_cycling'] + $counts['other_cycling'];

        if ($totalCycling === 0) {
            $cyclingFamily = ['mode' => 'off', 'disciplines' => []];
        } elseif (count($cycleDisciplines) >= 2) {
            $cyclingFamily = ['mode' => 'split', 'disciplines' => $cycleDisciplines];
        } else {
            $cyclingFamily = ['mode' => 'unified', 'disciplines' => $cycleDisciplines !== [] ? $cycleDisciplines : ['road_cycling']];
        }

        $trailSplitOut = $runningFamily['mode'] === 'split'
            && in_array('trail_running', $runningFamily['disciplines'], true);

        return [
            'mode' => 'auto',
            'pin_sub_disciplines' => false,
            'order' => self::DEFAULT_ORDER,
            'families' => [
                'running' => $runningFamily,
                'cycling' => $cyclingFamily,
            ],
            'secondary' => [],
            'rollups' => [
                'trail_running' => $trailSplitOut ? 'own' : 'running',
                'track_running' => in_array('track_running', $runningFamily['disciplines'], true) && $runningFamily['mode'] === 'split'
                    ? 'own'
                    : 'road_running',
                'gravel_cycling' => 'own',
                'indoor_cycling' => 'road_cycling',
                'ebike_cycling' => 'own',
            ],
            'best_efforts' => [
                'trail_in_running_prs' => ! $trailSplitOut,
                'cycling_disciplines' => ['road'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return ResolvedSportsConfig
     */
    private function normalizeCustomConfig(array $raw): array
    {
        $rawFamilies = is_array($raw['families'] ?? null) ? $raw['families'] : [];
        $rawRunning = is_array($rawFamilies['running'] ?? null) ? $rawFamilies['running'] : [];
        $rawCycling = is_array($rawFamilies['cycling'] ?? null) ? $rawFamilies['cycling'] : [];
        $rawRollups = is_array($raw['rollups'] ?? null) ? $raw['rollups'] : [];
        $rawBe = is_array($raw['best_efforts'] ?? null) ? $raw['best_efforts'] : [];

        $runMode = in_array($rawRunning['mode'] ?? null, ['off', 'unified', 'split'], true)
            ? (string) $rawRunning['mode']
            : 'unified';
        $validRunDiscs = ['road_running', 'trail_running', 'track_running', 'treadmill_running'];
        $runDiscs = array_values(array_intersect(
            is_array($rawRunning['disciplines'] ?? null) ? array_map('strval', $rawRunning['disciplines']) : ['road_running'],
            $validRunDiscs,
        ));
        if ($runMode === 'split' && $runDiscs === []) {
            $runDiscs = ['road_running'];
        }

        $cycleMode = in_array($rawCycling['mode'] ?? null, ['off', 'unified', 'split'], true)
            ? (string) $rawCycling['mode']
            : 'unified';
        $validCycleDiscs = ['road_cycling', 'gravel_cycling', 'mtb_cycling', 'indoor_cycling', 'ebike_cycling', 'commute_cycling'];
        $cycleDiscs = array_values(array_intersect(
            is_array($rawCycling['disciplines'] ?? null) ? array_map('strval', $rawCycling['disciplines']) : ['road_cycling'],
            $validCycleDiscs,
        ));
        if ($cycleMode === 'split' && $cycleDiscs === []) {
            $cycleDiscs = ['road_cycling'];
        }

        $secondary = array_values(array_intersect(
            is_array($raw['secondary'] ?? null) ? array_map('strval', $raw['secondary']) : [],
            self::SECONDARY_KEYS,
        ));

        $rawOrder = is_array($raw['order'] ?? null) ? array_map('strval', $raw['order']) : self::DEFAULT_ORDER;
        $order = array_values(array_intersect($rawOrder, self::DEFAULT_ORDER));
        foreach (self::DEFAULT_ORDER as $fallback) {
            if (! in_array($fallback, $order, true)) {
                $order[] = $fallback;
            }
        }

        $trailRollup = ($rawRollups['trail_running'] ?? null) === 'own' ? 'own' : 'running';
        $trackRollup = ($rawRollups['track_running'] ?? null) === 'own' ? 'own' : 'road_running';
        $gravelRollup = in_array($rawRollups['gravel_cycling'] ?? null, ['own', 'road_cycling', 'mtb_cycling'], true)
            ? (string) $rawRollups['gravel_cycling']
            : 'own';
        $indoorRollup = ($rawRollups['indoor_cycling'] ?? null) === 'own' ? 'own' : 'road_cycling';
        $ebikeRollup = in_array($rawRollups['ebike_cycling'] ?? null, ['own', 'mtb_cycling', 'exclude'], true)
            ? (string) $rawRollups['ebike_cycling']
            : 'own';

        $validBeCycling = ['road', 'gravel', 'mtb', 'ebike'];
        $cyclingBe = array_values(array_intersect(
            is_array($rawBe['cycling_disciplines'] ?? null) ? array_map('strval', $rawBe['cycling_disciplines']) : ['road'],
            $validBeCycling,
        ));

        $trailSplitDefault = ! ($runMode === 'split' && in_array('trail_running', $runDiscs, true));

        return [
            'mode' => 'custom',
            'pin_sub_disciplines' => (bool) ($raw['pin_sub_disciplines'] ?? false),
            'order' => $order,
            'families' => [
                'running' => [
                    'mode' => $runMode,
                    'disciplines' => $runDiscs,
                ],
                'cycling' => [
                    'mode' => $cycleMode,
                    'disciplines' => $cycleDiscs,
                ],
            ],
            'secondary' => $secondary,
            'rollups' => [
                'trail_running' => $trailRollup,
                'track_running' => $trackRollup,
                'gravel_cycling' => $gravelRollup,
                'indoor_cycling' => $indoorRollup,
                'ebike_cycling' => $ebikeRollup,
            ],
            'best_efforts' => [
                'trail_in_running_prs' => (bool) ($rawBe['trail_in_running_prs'] ?? $trailSplitDefault),
                'cycling_disciplines' => $cyclingBe,
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function activityCountsByBucket(): array
    {
        $counts = [
            'road_running' => 0,
            'trail_running' => 0,
            'track_running' => 0,
            'treadmill_running' => 0,
            'road_cycling' => 0,
            'gravel_cycling' => 0,
            'mtb_cycling' => 0,
            'indoor_cycling' => 0,
            'ebike_cycling' => 0,
            'commute_cycling' => 0,
            'other_cycling' => 0,
            'hiking' => 0,
            'walking' => 0,
            'swimming' => 0,
            'cardio' => 0,
        ];

        if (! Schema::hasTable('activities')) {
            return $counts;
        }

        $disciplineColumn = Schema::hasColumn('activities', 'sport_discipline')
            ? 'sport_discipline'
            : 'biking_discipline';

        $query = DB::table('activities')
            ->selectRaw("sport_category, COALESCE(discipline_override, {$disciplineColumn}) as effective_discipline, is_indoor, COUNT(*) as total");

        if (Schema::hasColumn('activities', 'excluded_at')) {
            $query->whereNull('excluded_at');
        }

        $rows = $query
            ->groupByRaw("sport_category, COALESCE(discipline_override, {$disciplineColumn}), is_indoor")
            ->get();

        foreach ($rows as $row) {
            $category = (string) $row->sport_category;
            $discipline = is_string($row->effective_discipline) ? $row->effective_discipline : null;
            $isIndoor = (bool) $row->is_indoor;
            $total = (int) $row->total;

            if ($category === 'running') {
                if ($isIndoor) {
                    $counts['treadmill_running'] += $total;
                } elseif ($discipline === 'trail') {
                    $counts['trail_running'] += $total;
                } elseif ($discipline === 'track') {
                    $counts['track_running'] += $total;
                } else {
                    $counts['road_running'] += $total;
                }
            } elseif ($category === 'biking') {
                if ($isIndoor) {
                    $counts['indoor_cycling'] += $total;
                } elseif ($discipline === 'road') {
                    $counts['road_cycling'] += $total;
                } elseif ($discipline === 'gravel') {
                    $counts['gravel_cycling'] += $total;
                } elseif ($discipline === 'mtb') {
                    $counts['mtb_cycling'] += $total;
                } elseif ($discipline === 'ebike') {
                    $counts['ebike_cycling'] += $total;
                } elseif ($discipline === 'commute') {
                    $counts['commute_cycling'] += $total;
                } else {
                    $counts['other_cycling'] += $total;
                }
            } elseif (isset($counts[$category])) {
                $counts[$category] += $total;
            }
        }

        return $counts;
    }

    /**
     * @param  SportCatalogEntry  $entry
     * @return array{
     *     key: string,
     *     label: string,
     *     short_label: string,
     *     href: string,
     *     icon: string,
     *     color_token: string,
     *     accent_hex: string,
     *     parent: ?string
     * }
     */
    private function toNavItem(array $entry): array
    {
        return [
            'key' => $entry['key'],
            'label' => $entry['label'],
            'short_label' => $entry['short_label'],
            'href' => $entry['href'],
            'icon' => $entry['icon'],
            'color_token' => $entry['color_token'],
            'accent_hex' => $entry['accent_hex'],
            'parent' => $entry['parent'],
        ];
    }
}
