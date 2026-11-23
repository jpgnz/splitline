<?php

namespace App\Providers;

use App\Jobs\SyncActivityStreamJob;
use App\Jobs\SyncActivityWeatherJob;
use App\Services\Garmin\GarminClient;
use App\Services\Garmin\GarminTokenManager;
use App\Support\AppSettings;
use App\Support\AppVersion;
use App\Support\SportRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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

        $this->app->singleton(GarminTokenManager::class, function (Application $app): GarminTokenManager {
            return new GarminTokenManager(
                apiKey: (string) config('services.garmin.key', ''),
                baseUrl: (string) config('services.garmin.base_url', 'https://personal.connectapi.garmin.com'),
                timeout: (int) config('services.garmin.timeout', 30),
                cache: $app->make(CacheRepository::class),
            );
        });

        $this->app->singleton(GarminClient::class, function (Application $app): GarminClient {
            return new GarminClient(
                tokenManager: $app->make(GarminTokenManager::class),
                baseUrl: (string) config('services.garmin.base_url', 'https://personal.connectapi.garmin.com'),
                timeout: (int) config('services.garmin.timeout', 30),
                cache: $app->make(CacheRepository::class),
                maxRequestsPerMinute: (int) config('services.garmin.rate_limit.max_requests_per_minute', 85),
                maxRateLimitRetries: (int) config('services.garmin.rate_limit.max_retries', 5),
                maxRetryWaitSeconds: (int) config('services.garmin.rate_limit.max_retry_wait_seconds', 120),
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
