<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Resolves and persists the primary telemetry provider for each daily health domain.
 *
 * @phpstan-type DomainProviderSummary array{
 *     id: string,
 *     label: string,
 *     count: int
 * }
 * @phpstan-type DomainSettingsEntry array{
 *     key: string,
 *     label: string,
 *     description: string,
 *     configured: string,
 *     resolved: ?string,
 *     resolved_label: ?string,
 *     has_multiple_providers: bool,
 *     providers: list<DomainProviderSummary>
 * }
 * @phpstan-type HealthSourcesSettingsPayload array{
 *     domains: list<DomainSettingsEntry>,
 *     has_multiple_providers: bool
 * }
 */
class HealthSourceRegistry
{
    public const string SETTINGS_KEY = 'health.primary_sources';

    public const string MODE_AUTO = 'auto';

    public const string DEFAULT_PREFERRED_PROVIDER = 'garmin_personal';

    /**
     * Supported health domains, their underlying table(s), and recency date column.
     *
     * @var array<string, array{
     *     label: string,
     *     description: string,
     *     table: string,
     *     tables: list<string>,
     *     date_column: ?string
     * }>
     */
    public const array DOMAINS = [
        'daily_metrics' => [
            'label' => 'Daily Wellness',
            'description' => 'Steps, resting HR, stress, Body Battery, SpO2, respiration, and hydration.',
            'table' => 'daily_metrics',
            'tables' => ['daily_metrics'],
            'date_column' => 'calendar_date',
        ],
        'sleep' => [
            'label' => 'Sleep',
            'description' => 'Sleep duration, sleep stages, score, and nightly sleep need.',
            'table' => 'sleep_records',
            'tables' => ['sleep_records'],
            'date_column' => 'calendar_date',
        ],
        'hrv' => [
            'label' => 'HRV Status',
            'description' => 'Overnight HRV average, 5-minute peak, and baseline balance status.',
            'table' => 'hrv_records',
            'tables' => ['hrv_records'],
            'date_column' => 'calendar_date',
        ],
        'weight' => [
            'label' => 'Weight & Body Composition',
            'description' => 'Weight measurements, body composition, and weight goals.',
            'table' => 'weight_measurements',
            'tables' => ['weight_measurements', 'weight_goals'],
            'date_column' => 'calendar_date',
        ],
        'vo2_max' => [
            'label' => 'VO2 Max',
            'description' => 'Running and cycling aerobic fitness estimates.',
            'table' => 'vo2_max_records',
            'tables' => ['vo2_max_records'],
            'date_column' => 'calendar_date',
        ],
        'fitness_age' => [
            'label' => 'Fitness Age',
            'description' => 'Estimated biological fitness age and contributing factors.',
            'table' => 'fitness_age_records',
            'tables' => ['fitness_age_records'],
            'date_column' => 'calendar_date',
        ],
        'heart_rate_zones' => [
            'label' => 'Heart Rate Zones',
            'description' => 'Configured max HR, resting HR, and sport heart-rate zone boundaries.',
            'table' => 'heart_rate_zones',
            'tables' => ['heart_rate_zones'],
            'date_column' => null,
        ],
    ];

    /**
     * Per-table ranking date column and the natural key columns that identify the "same" record across providers.
     *
     * In Auto mode a lower-ranked provider's row is only shown when no higher-ranked provider has a row with the same key,
     * so adding a second provider fills gaps instead of hiding existing history.
     *
     * @var array<string, array{date_column: ?string, key_columns: list<string>}>
     */
    private const array TABLES = [
        'daily_metrics' => ['date_column' => 'calendar_date', 'key_columns' => ['calendar_date']],
        'sleep_records' => ['date_column' => 'calendar_date', 'key_columns' => ['calendar_date']],
        'hrv_records' => ['date_column' => 'calendar_date', 'key_columns' => ['calendar_date']],
        'weight_measurements' => ['date_column' => 'calendar_date', 'key_columns' => ['calendar_date']],
        'weight_goals' => ['date_column' => 'start_date', 'key_columns' => ['start_date']],
        'vo2_max_records' => ['date_column' => 'calendar_date', 'key_columns' => ['calendar_date', 'sport']],
        'fitness_age_records' => ['date_column' => 'calendar_date', 'key_columns' => ['calendar_date']],
        'heart_rate_zones' => ['date_column' => null, 'key_columns' => ['sport']],
    ];

    /**
     * Human-readable labels for known telemetry providers.
     *
     * @var array<string, string>
     */
    private const array PROVIDER_LABELS = [
        'garmin_personal' => 'Garmin Connect',
        'apple_health' => 'Apple Health',
        'oura' => 'Oura',
        'withings' => 'Withings',
        'whoop' => 'WHOOP',
        'coros' => 'COROS',
        'polar' => 'Polar',
        'wahoo' => 'Wahoo',
        'fit_import' => 'FIT File Import',
    ];

    /** @var array<string, string>|null */
    private ?array $configMemo = null;

    /** @var array<string, array<string, array{count: int, latest_date: ?string, latest_updated_at: ?string}>> */
    private array $providerStatsMemo = [];

    /** @var array<string, array<string, array{count: int, latest_date: ?string, latest_updated_at: ?string}>> */
    private array $tableStatsMemo = [];

    /** @var array<string, ?string> */
    private array $resolvedMemo = [];

    public function __construct(
        private readonly AppSettings $settings,
    ) {}

    public function flushMemo(): void
    {
        $this->configMemo = null;
        $this->providerStatsMemo = [];
        $this->tableStatsMemo = [];
        $this->resolvedMemo = [];
    }

    /**
     * Return all supported health domain keys.
     *
     * @return list<string>
     */
    public static function domainKeys(): array
    {
        return array_keys(self::DOMAINS);
    }

    /**
     * Return a human-readable display label for a provider identifier.
     */
    public function providerLabel(string $provider): string
    {
        if (isset(self::PROVIDER_LABELS[$provider])) {
            return self::PROVIDER_LABELS[$provider];
        }

        return Str::headline(str_replace(['_', '-'], ' ', $provider));
    }

    /**
     * Return the configured provider selection ('auto' or provider ID) for every domain.
     *
     * @return array<string, string>
     */
    public function config(): array
    {
        if ($this->configMemo !== null) {
            return $this->configMemo;
        }

        $raw = $this->settings->get(self::SETTINGS_KEY);
        $rawArray = is_array($raw) ? $raw : [];

        return $this->configMemo = $this->sanitizeSelections($rawArray);
    }

    /**
     * Return the configured mode/provider for a single health domain.
     */
    public function configuredSelection(string $domain): string
    {
        $this->assertValidDomain($domain);

        return $this->config()[$domain] ?? self::MODE_AUTO;
    }

    /**
     * Discover distinct provider IDs present in a domain's backing table(s).
     *
     * @return list<string>
     */
    public function availableProviders(string $domain): array
    {
        $this->assertValidDomain($domain);

        $providers = array_keys($this->providerStats($domain));

        usort($providers, static function (string $a, string $b): int {
            if ($a === $b) {
                return 0;
            }

            if ($a === self::DEFAULT_PREFERRED_PROVIDER) {
                return -1;
            }

            if ($b === self::DEFAULT_PREFERRED_PROVIDER) {
                return 1;
            }

            return strcmp($a, $b);
        });

        return $providers;
    }

    /**
     * Resolve the effective provider for a domain ('auto' picks the most recent, with garmin_personal tie-breaker).
     */
    public function resolveProvider(string $domain): ?string
    {
        $this->assertValidDomain($domain);

        if (array_key_exists($domain, $this->resolvedMemo)) {
            return $this->resolvedMemo[$domain];
        }

        $configured = $this->configuredSelection($domain);

        if ($configured !== self::MODE_AUTO) {
            return $this->resolvedMemo[$domain] = $configured;
        }

        $primaryStats = $this->tableStats(self::DOMAINS[$domain]['table']);
        $ranked = $this->rankProviders($primaryStats !== [] ? $primaryStats : $this->providerStats($domain));

        return $this->resolvedMemo[$domain] = $ranked[0] ?? null;
    }

    /**
     * Scope an Eloquent query to the domain's health source selection.
     *
     * An explicitly configured provider strictly filters the domain's primary table. In Auto mode, or for a secondary table
     * (e.g. weight goals) that holds no rows from the configured provider, rows are ranked by provider recency and a
     * lower-ranked provider only contributes records the higher-ranked providers do not have.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function applyScope(Builder $query, string $domain): Builder
    {
        $this->assertValidDomain($domain);

        $table = $query->getModel()->getTable();

        if (! in_array($table, self::DOMAINS[$domain]['tables'], true)) {
            throw new InvalidArgumentException("Table [{$table}] does not belong to health source domain [{$domain}].");
        }

        $configured = $this->configuredSelection($domain);
        $tableStats = $this->tableStats($table);

        if (
            $configured !== self::MODE_AUTO
            && ($table === self::DOMAINS[$domain]['table'] || isset($tableStats[$configured]))
        ) {
            return $query->where("{$table}.provider", $configured);
        }

        if (count($tableStats) < 2) {
            return $query;
        }

        return $this->applyProviderPrecedence($query, $table, $this->rankProviders($tableStats));
    }

    /**
     * Keep each row only when no higher-ranked provider has a row with the same natural key.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $rankedProviders
     * @return Builder<TModel>
     */
    private function applyProviderPrecedence(Builder $query, string $table, array $rankedProviders): Builder
    {
        $keyColumns = self::TABLES[$table]['key_columns'];

        return $query->where(function (Builder $precedenceQuery) use ($table, $rankedProviders, $keyColumns): void {
            foreach ($rankedProviders as $rank => $provider) {
                $higherRanked = array_slice($rankedProviders, 0, $rank);

                $precedenceQuery->orWhere(function (Builder $providerQuery) use ($table, $provider, $higherRanked, $keyColumns): void {
                    $providerQuery->where("{$table}.provider", $provider);

                    if ($higherRanked === []) {
                        return;
                    }

                    $providerQuery->whereNotExists(function (QueryBuilder $preferredQuery) use ($table, $higherRanked, $keyColumns): void {
                        $preferredQuery
                            ->selectRaw('1')
                            ->from("{$table} as preferred_source")
                            ->whereIn('preferred_source.provider', $higherRanked);

                        foreach ($keyColumns as $keyColumn) {
                            $preferredQuery->whereColumn("preferred_source.{$keyColumn}", "{$table}.{$keyColumn}");
                        }
                    });
                });
            }
        });
    }

    /**
     * Order providers by most recent record date, then most recent update, preferring garmin_personal and then alphabetical on ties.
     *
     * @param  array<string, array{count: int, latest_date: ?string, latest_updated_at: ?string}>  $stats
     * @return list<string>
     */
    private function rankProviders(array $stats): array
    {
        $providers = array_map('strval', array_keys($stats));

        usort($providers, static function (string $a, string $b) use ($stats): int {
            $dateCompare = strcmp((string) ($stats[$b]['latest_date'] ?? ''), (string) ($stats[$a]['latest_date'] ?? ''));

            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            $updatedCompare = strcmp((string) ($stats[$b]['latest_updated_at'] ?? ''), (string) ($stats[$a]['latest_updated_at'] ?? ''));

            if ($updatedCompare !== 0) {
                return $updatedCompare;
            }

            if ($a === self::DEFAULT_PREFERRED_PROVIDER && $b !== self::DEFAULT_PREFERRED_PROVIDER) {
                return -1;
            }

            if ($b === self::DEFAULT_PREFERRED_PROVIDER && $a !== self::DEFAULT_PREFERRED_PROVIDER) {
                return 1;
            }

            return strcmp($a, $b);
        });

        return $providers;
    }

    /**
     * Build the Settings UI payload listing every health domain, its detected providers, and active resolved source.
     *
     * @return HealthSourcesSettingsPayload
     */
    public function settingsPayload(): array
    {
        $domains = [];
        $anyMultiple = false;

        foreach (self::DOMAINS as $key => $meta) {
            $stats = $this->providerStats($key);
            $available = $this->availableProviders($key);
            $configured = $this->configuredSelection($key);
            $resolved = $this->resolveProvider($key);
            $hasMultiple = count($available) >= 2;

            if ($hasMultiple) {
                $anyMultiple = true;
            }

            $providers = [];

            foreach ($available as $providerId) {
                $providers[] = [
                    'id' => $providerId,
                    'label' => $this->providerLabel($providerId),
                    'count' => $stats[$providerId]['count'] ?? 0,
                ];
            }

            if ($configured !== self::MODE_AUTO && ! isset($stats[$configured])) {
                $providers[] = [
                    'id' => $configured,
                    'label' => $this->providerLabel($configured),
                    'count' => 0,
                ];
            }

            $domains[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'configured' => $configured,
                'resolved' => $resolved,
                'resolved_label' => $resolved !== null ? $this->providerLabel($resolved) : null,
                'has_multiple_providers' => $hasMultiple,
                'providers' => $providers,
            ];
        }

        return [
            'domains' => $domains,
            'has_multiple_providers' => $anyMultiple,
        ];
    }

    /**
     * Sanitize and persist primary health source selections into AppSettings.
     *
     * @param  array<string, mixed>  $selections
     * @return array<string, string>
     */
    public function saveConfig(array $selections): array
    {
        $sanitized = $this->sanitizeSelections($selections);

        $this->settings->set(self::SETTINGS_KEY, $sanitized);
        $this->flushMemo();

        return $sanitized;
    }

    /**
     * @param  array<mixed, mixed>  $selections
     * @return array<string, string>
     */
    private function sanitizeSelections(array $selections): array
    {
        $sanitized = [];

        foreach (array_keys(self::DOMAINS) as $domain) {
            $candidate = $selections[$domain] ?? self::MODE_AUTO;

            if (! is_string($candidate)) {
                $sanitized[$domain] = self::MODE_AUTO;

                continue;
            }

            $trimmed = strtolower(trim($candidate));

            if ($trimmed === '' || $trimmed === self::MODE_AUTO || preg_match('/^[a-z0-9_-]{1,64}$/', $trimmed) !== 1) {
                $sanitized[$domain] = self::MODE_AUTO;

                continue;
            }

            $sanitized[$domain] = $trimmed;
        }

        return $sanitized;
    }

    /**
     * Merge per-table provider stats for a domain; only the primary table contributes recency dates.
     *
     * @return array<string, array{count: int, latest_date: ?string, latest_updated_at: ?string}>
     */
    private function providerStats(string $domain): array
    {
        if (isset($this->providerStatsMemo[$domain])) {
            return $this->providerStatsMemo[$domain];
        }

        $meta = self::DOMAINS[$domain];
        $stats = [];

        foreach ($meta['tables'] as $table) {
            foreach ($this->tableStats($table) as $provider => $tableStat) {
                $latestDate = $table === $meta['table'] ? $tableStat['latest_date'] : null;
                $latestUpdatedAt = $tableStat['latest_updated_at'];

                if (! isset($stats[$provider])) {
                    $stats[$provider] = [
                        'count' => $tableStat['count'],
                        'latest_date' => $latestDate,
                        'latest_updated_at' => $latestUpdatedAt,
                    ];

                    continue;
                }

                $stats[$provider]['count'] += $tableStat['count'];

                if ($latestDate !== null && ($stats[$provider]['latest_date'] === null || strcmp($latestDate, $stats[$provider]['latest_date']) > 0)) {
                    $stats[$provider]['latest_date'] = $latestDate;
                }

                if ($latestUpdatedAt !== null && ($stats[$provider]['latest_updated_at'] === null || strcmp($latestUpdatedAt, $stats[$provider]['latest_updated_at']) > 0)) {
                    $stats[$provider]['latest_updated_at'] = $latestUpdatedAt;
                }
            }
        }

        return $this->providerStatsMemo[$domain] = $stats;
    }

    /**
     * @return array<string, array{count: int, latest_date: ?string, latest_updated_at: ?string}>
     */
    private function tableStats(string $table): array
    {
        if (isset($this->tableStatsMemo[$table])) {
            return $this->tableStatsMemo[$table];
        }

        if (! Schema::hasTable($table)) {
            return $this->tableStatsMemo[$table] = [];
        }

        $dateColumn = self::TABLES[$table]['date_column'];
        $select = $dateColumn !== null
            ? "provider, COUNT(*) as row_count, MAX({$dateColumn}) as latest_date, MAX(updated_at) as latest_updated_at"
            : 'provider, COUNT(*) as row_count, NULL as latest_date, MAX(updated_at) as latest_updated_at';

        $rows = DB::table($table)
            ->selectRaw($select)
            ->whereNotNull('provider')
            ->where('provider', '!=', '')
            ->groupBy('provider')
            ->get();

        $stats = [];

        foreach ($rows as $row) {
            $provider = is_string($row->provider ?? null) ? trim($row->provider) : '';

            if ($provider === '') {
                continue;
            }

            $stats[$provider] = [
                'count' => (int) ($row->row_count ?? 0),
                'latest_date' => is_string($row->latest_date ?? null) && $row->latest_date !== '' ? $row->latest_date : null,
                'latest_updated_at' => is_string($row->latest_updated_at ?? null) && $row->latest_updated_at !== '' ? $row->latest_updated_at : null,
            ];
        }

        return $this->tableStatsMemo[$table] = $stats;
    }

    private function assertValidDomain(string $domain): void
    {
        if (! isset(self::DOMAINS[$domain])) {
            throw new InvalidArgumentException("Unsupported health source domain [{$domain}].");
        }
    }
}
