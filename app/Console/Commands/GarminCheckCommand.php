<?php

namespace App\Console\Commands;

use App\Services\Garmin\GarminApiException;
use App\Services\Garmin\GarminClient;
use App\Services\Garmin\GarminTokenManager;
use Illuminate\Console\Command;

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
    public function handle(GarminTokenManager $tokenManager, GarminClient $client): int
    {
        try {
            $tokenManager->getAccessToken((bool) $this->option('refresh'));
            $this->components->info('Garmin bearer token acquired.');

            $settings = $client->userProfileSettings();
        } catch (GarminApiException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($settings === null) {
            $this->components->warn('Connected to Garmin, but user profile settings returned an empty response.');

            return self::SUCCESS;
        }

        $details = array_filter([
            'Measurement System' => isset($settings['measurementSystem']) && is_string($settings['measurementSystem'])
                ? $settings['measurementSystem']
                : null,
            'Time Zone' => isset($settings['timeZone']) && is_string($settings['timeZone'])
                ? $settings['timeZone']
                : null,
            'Fields Returned' => (string) count($settings),
        ], static fn (?string $value): bool => $value !== null);

        $this->components->info('Successfully connected to the Garmin Connect Personal API.');

        foreach ($details as $label => $value) {
            $this->components->twoColumnDetail($label, $value);
        }

        return self::SUCCESS;
    }
}
