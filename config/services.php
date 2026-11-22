<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'garmin' => [
        'key' => env('GARMIN_KEY'),
        'base_url' => env('GARMIN_BASE_URL', 'https://personal.connectapi.garmin.com'),
        'timeout' => (int) env('GARMIN_TIMEOUT', 30),
        'rate_limit' => [
            'max_requests_per_minute' => (int) env('GARMIN_RATE_LIMIT_PER_MINUTE', 85),
            'max_retries' => (int) env('GARMIN_RATE_LIMIT_MAX_RETRIES', 5),
            'max_retry_wait_seconds' => (int) env('GARMIN_RATE_LIMIT_MAX_WAIT_SECONDS', 120),
        ],
    ],

    'open_meteo' => [
        'enabled' => (bool) env('OPEN_METEO_ENABLED', true),
        'auto_sync' => (bool) env('OPEN_METEO_AUTO_SYNC', true),
        'forecast_url' => env('OPEN_METEO_FORECAST_URL', 'https://api.open-meteo.com/v1/forecast'),
        'historical_forecast_url' => env('OPEN_METEO_HISTORICAL_FORECAST_URL', 'https://historical-forecast-api.open-meteo.com/v1/forecast'),
        'archive_url' => env('OPEN_METEO_ARCHIVE_URL', 'https://archive-api.open-meteo.com/v1/archive'),
        'timeout' => (int) env('OPEN_METEO_TIMEOUT', 15),
        'max_requests_per_minute' => (int) env('OPEN_METEO_RATE_LIMIT_PER_MINUTE', 50),
    ],

];
