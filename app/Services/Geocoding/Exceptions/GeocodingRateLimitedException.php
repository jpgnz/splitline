<?php

namespace App\Services\Geocoding\Exceptions;

use RuntimeException;
use Throwable;

class GeocodingRateLimitedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $retryAfterSeconds = 60,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 429, $previous);
    }
}
