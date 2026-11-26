<?php

namespace App\Providers;

use App\Jobs\GeocodeRouteClusterJob;
use App\Jobs\SyncActivityStreamJob;
use App\Jobs\SyncActivityWeatherJob;
use App\Support\AppSettings;
use App\Support\AppVersion;
use App\Support\HealthSourceRegistry;
use App\Support\SportRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Splitline\Core\Analytics\ActivityClassifier;
use Splitline\Core\Contracts\CacheStore;
use Splitline\Core\Contracts\HttpTransport;
use Splitline\Core\Contracts\RateLimiter;
use Splitline\Core\Contracts\Sleeper;
use Splitline\Parsers\Fit\FitParser;
use Splitline\ProviderFileImport\FitFileImportProvider;
use Splitline\ProviderGarminPersonal\GarminPersonalClient;
use Splitline\ProviderGarminPersonal\GarminPersonalConfig;
use Splitline\ProviderGarminPersonal\GarminPersonalMapper;
use Splitline\ProviderGarminPersonal\GarminPersonalProvider;
use Splitline\ProviderGarminPersonal\GarminPersonalTokenManager;
use Splitline\Weather\ActivityWaypointSampler;
use Splitline\Weather\OpenMeteoClient;
use Splitline\Weather\OpenMeteoConfig;
use Splitline\Weather\WeatherEnricher;
use Splitline\Weather\WindVectorMath;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(AppSettings::class);
        $this->app->singleton(AppVersion::class);
        $this->app->scoped(SportRegistry::class);
        $this->app->scoped(HealthSourceRegistry::class);

        $this->app->bind(GarminPersonalConfig::class, static function (): GarminPersonalConfig {
            $apiKey = config('services.garmin.key');

            return new GarminPersonalConfig(
                baseUrl: (string) config('services.garmin.base_url', GarminPersonalConfig::DEFAULT_BASE_URL),
                timeout: (int) config('services.garmin.timeout', 30),
                maxRequestsPerMinute: (int) config('services.garmin.rate_limit.max_requests_per_minute', 85),
                maxRateLimitRetries: (int) config('services.garmin.rate_limit.max_retries', 5),
                maxRetryWaitSeconds: (int) config('services.garmin.rate_limit.max_retry_wait_seconds', 120),
                defaultApiKey: is_string($apiKey) && $apiKey !== '' ? $apiKey : null,
            );
        });

        $this->app->bind(GarminPersonalTokenManager::class, static function (Application $app): GarminPersonalTokenManager {
            return new GarminPersonalTokenManager(
                http: $app->make(HttpTransport::class),
                cache: $app->make(CacheStore::class),
                config: $app->make(GarminPersonalConfig::class),
            );
        });

        $this->app->bind(GarminPersonalClient::class, static function (Application $app): GarminPersonalClient {
            return new GarminPersonalClient(
                tokenManager: $app->make(GarminPersonalTokenManager::class),
                http: $app->make(HttpTransport::class),
                cache: $app->make(CacheStore::class),
                rateLimiter: $app->make(RateLimiter::class),
                sleeper: $app->make(Sleeper::class),
                config: $app->make(GarminPersonalConfig::class),
            );
        });

        $this->app->bind(GarminPersonalProvider::class, static function (Application $app): GarminPersonalProvider {
            return new GarminPersonalProvider(
                client: $app->make(GarminPersonalClient::class),
                mapper: $app->make(GarminPersonalMapper::class),
            );
        });

        $this->app->bind(OpenMeteoConfig::class, static function (): OpenMeteoConfig {
            return new OpenMeteoConfig(
                forecastUrl: (string) config('services.open_meteo.forecast_url', OpenMeteoConfig::DEFAULT_FORECAST_URL),
                historicalForecastUrl: (string) config('services.open_meteo.historical_forecast_url', OpenMeteoConfig::DEFAULT_HISTORICAL_FORECAST_URL),
                archiveUrl: (string) config('services.open_meteo.archive_url', OpenMeteoConfig::DEFAULT_ARCHIVE_URL),
                timeout: (int) config('services.open_meteo.timeout', 15),
                maxRetries: (int) config('services.open_meteo.max_retries', 2),
                maxRequestsPerMinute: (int) config('services.open_meteo.max_requests_per_minute', 50),
            );
        });

        $this->app->bind(OpenMeteoClient::class, static function (Application $app): OpenMeteoClient {
            return new OpenMeteoClient(
                http: $app->make(HttpTransport::class),
                rateLimiter: $app->make(RateLimiter::class),
                sleeper: $app->make(Sleeper::class),
                config: $app->make(OpenMeteoConfig::class),
            );
        });

        $this->app->bind(WeatherEnricher::class, static function (Application $app): WeatherEnricher {
            return new WeatherEnricher(
                sampler: $app->make(ActivityWaypointSampler::class),
                client: $app->make(OpenMeteoClient::class),
                math: $app->make(WindVectorMath::class),
            );
        });

        $this->app->singleton(FitParser::class);
        $this->app->singleton(FitFileImportProvider::class, static function (Application $app): FitFileImportProvider {
            return new FitFileImportProvider(
                parser: $app->make(FitParser::class),
                classifier: $app->make(ActivityClassifier::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        $connection = (string) config('queue.default', 'database');
        $primaryQueue = trim((string) config("queue.connections.{$connection}.queue", 'default'));
        $queues = implode(',', array_values(array_unique([
            $primaryQueue !== '' ? $primaryQueue : 'default',
            SyncActivityStreamJob::QUEUE,
            SyncActivityWeatherJob::QUEUE,
            GeocodeRouteClusterJob::QUEUE,
        ])));

        DevCommands::artisan("queue:listen --queue={$queues} --tries=1 --timeout=0", 'queue');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
