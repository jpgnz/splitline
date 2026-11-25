<?php

namespace App\Console\Commands;

use App\Support\AppSettings;
use Illuminate\Console\Command;
use Splitline\Core\Data\ProviderContext;
use Splitline\Core\Exceptions\ProviderException;
use Splitline\ProviderGarminPersonal\GarminPersonalClient;
use Splitline\ProviderGarminPersonal\GarminPersonalTokenManager;

class GarminCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'garmin:check {--refresh : Force a fresh bearer token exchange}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify Garmin Connect Personal API credentials and connectivity';

    /**
     * Execute the console command.
     */
    public function handle(
        GarminPersonalTokenManager $tokenManager,
        GarminPersonalClient $client,
        AppSettings $settings,
    ): int {
        $context = new ProviderContext(
            scopeKey: 'default',
            timezone: $settings->displayTimezone(),
            credentials: [
                'api_key' => (string) config('services.garmin.key', ''),
            ],
        );

        try {
            $tokenManager->getAccessToken($context, (bool) $this->option('refresh'));
            $this->components->info('Garmin bearer token acquired.');

            $profileSettings = $client->userProfileSettings($context);
        } catch (ProviderException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($profileSettings === null) {
            $this->components->warn('Connected to Garmin, but user profile settings returned an empty response.');

            return self::SUCCESS;
        }

        $details = array_filter([
            'Measurement System' => isset($profileSettings['measurementSystem']) && is_string($profileSettings['measurementSystem'])
                ? $profileSettings['measurementSystem']
                : null,
            'Time Zone' => isset($profileSettings['timeZone']) && is_string($profileSettings['timeZone'])
                ? $profileSettings['timeZone']
                : null,
            'Fields Returned' => (string) count($profileSettings),
        ], static fn (?string $value): bool => $value !== null);

        $this->components->info('Successfully connected to the Garmin Connect Personal API.');

        foreach ($details as $label => $value) {
            $this->components->twoColumnDetail($label, $value);
        }

        return self::SUCCESS;
    }
}
