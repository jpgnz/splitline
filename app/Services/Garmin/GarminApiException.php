<?php

namespace App\Services\Garmin;

use RuntimeException;
use Throwable;

class GarminApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $method = null,
        public readonly ?string $url = null,
        public readonly ?string $responseBody = null,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
        public readonly bool $archiveUnavailable = false,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }

    public static function missingApiKey(): self
    {
        return new self('GARMIN_KEY is not configured.');
    }

    public static function invalidTokenResponse(string $reason): self
    {
        return new self("Garmin token endpoint returned an invalid response: {$reason}");
    }

    public static function invalidArgument(string $message): self
    {
        return new self($message);
    }

    public static function httpError(
        string $method,
        string $url,
        int $statusCode,
        string $responseBody = '',
        ?int $retryAfterSeconds = null,
    ): self {
        $summary = trim($responseBody);
        $suffix = $summary !== '' ? ": {$summary}" : '';

        return new self(
            message: "Garmin API {$method} {$url} failed with HTTP {$statusCode}{$suffix}",
            statusCode: $statusCode,
            method: $method,
            url: $url,
            responseBody: $responseBody,
            retryAfterSeconds: $retryAfterSeconds,
        );
    }

    public static function archiveUnavailable(
        string $url,
        int $statusCode,
        string $responseBody = '',
    ): self {
        $summary = trim($responseBody);
        $suffix = $summary !== '' ? ": {$summary}" : '';

        return new self(
            message: "Garmin API GET {$url} failed with HTTP {$statusCode}{$suffix}",
            statusCode: $statusCode,
            method: 'GET',
            url: $url,
            responseBody: $responseBody,
            archiveUnavailable: true,
        );
    }

    public function isRateLimited(): bool
    {
        return $this->statusCode === 429;
    }

    public function isArchiveUnavailable(): bool
    {
        return $this->archiveUnavailable;
    }

    /**
     * @return array{status_code: int|null, method: string|null, url: string|null, retry_after_seconds: int|null}
     */
    public function context(): array
    {
        return [
            'status_code' => $this->statusCode,
            'method' => $this->method,
            'url' => $this->url,
            'retry_after_seconds' => $this->retryAfterSeconds,
        ];
    }
}
