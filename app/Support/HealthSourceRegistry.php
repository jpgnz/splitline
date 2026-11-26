<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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

    /** @var array<string, ?string> */
    private array $resolvedMemo = [];

    public function __construct(
        private readonly AppSettings $settings,
    ) {}

    public function flushMemo(): void
    {
        $this->configMemo = null;
        $this->providerStatsMemo = [];
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

        $stats = $this->providerStats($domain);

        if ($stats === []) {
            return $this->resolvedMemo[$domain] = null;
        }

        $providers = array_keys($stats);

        if (count($providers) === 1) {
            return $this->resolvedMemo[$domain] = $providers[0];
        }

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

        return $this->resolvedMemo[$domain] = $providers[0];
    }

    /**
     * Scope an Eloquent query to the domain's primary provider when explicitly configured or when multiple providers exist.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function applyScope(Builder $query, string $domain): Builder
    {
        $this->assertValidDomain($domain);

        $configured = $this->configuredSelection($domain);
        $resolved = $this->resolveProvider($domain);

        if ($resolved === null) {
            return $query;
        }

        if ($configured !== self::MODE_AUTO || count($this->providerStats($domain)) >= 2) {
            $table = $query->getModel()->getTable();
            $query->where("{$table}.provider", $resolved);
        }

        return $query;
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
            if (! Schema::hasTable($table)) {
                continue;
            }

            $dateColumn = $table === $meta['table'] ? $meta['date_column'] : null;
            $select = $dateColumn !== null
                ? "provider, COUNT(*) as row_count, MAX({$dateColumn}) as latest_date, MAX(updated_at) as latest_updated_at"
                : 'provider, COUNT(*) as row_count, NULL as latest_date, MAX(updated_at) as latest_updated_at';

            $rows = DB::table($table)
                ->selectRaw($select)
                ->whereNotNull('provider')
                ->where('provider', '!=', '')
                ->groupBy('provider')
                ->get();

            foreach ($rows as $row) {
                $provider = is_string($row->provider ?? null) ? trim($row->provider) : '';

                if ($provider === '') {
                    continue;
                }

                $count = (int) ($row->row_count ?? 0);
                $latestDate = is_string($row->latest_date ?? null) && $row->latest_date !== ''
                    ? $row->latest_date
                    : null;
                $latestUpdatedAt = is_string($row->latest_updated_at ?? null) && $row->latest_updated_at !== ''
                    ? $row->latest_updated_at
                    : null;

                if (! isset($stats[$provider])) {
                    $stats[$provider] = [
                        'count' => $count,
                        'latest_date' => $latestDate,
                        'latest_updated_at' => $latestUpdatedAt,
                    ];

                    continue;
                }

                $stats[$provider]['count'] += $count;

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

    private function assertValidDomain(string $domain): void
    {
        if (! isset(self::DOMAINS[$domain])) {
            throw new InvalidArgumentException("Unsupported health source domain [{$domain}].");
        }
    }
}
