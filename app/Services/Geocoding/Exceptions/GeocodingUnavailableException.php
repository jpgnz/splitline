<?php

namespace App\Services\Geocoding\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when the geocoding provider is temporarily unreachable, blocked, or cooling down,
 * so callers should pause and retry later instead of recording a lookup attempt.
 */
class GeocodingUnavailableException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $retryAfterSeconds = 60,
        int $code = 503,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
