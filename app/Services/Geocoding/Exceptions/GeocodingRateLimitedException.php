<?php

namespace App\Services\Geocoding\Exceptions;

use Throwable;

class GeocodingRateLimitedException extends GeocodingUnavailableException
{
    public function __construct(
        string $message,
        int $retryAfterSeconds = 60,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $retryAfterSeconds, 429, $previous);
    }
}
