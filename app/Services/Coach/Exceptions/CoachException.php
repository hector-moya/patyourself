<?php

namespace App\Services\Coach\Exceptions;

use RuntimeException;

/**
 * Raised when the CoachService cannot fulfil a request (missing credentials,
 * provider error, or unparseable output).
 */
class CoachException extends RuntimeException
{
    public static function missingCredentials(string $driver): self
    {
        return new self("Missing API credentials for the [{$driver}] coach driver.");
    }

    public static function requestFailed(string $driver, int $status, string $body): self
    {
        return new self("The [{$driver}] coach provider returned HTTP {$status}: {$body}");
    }

    public static function emptyResponse(string $driver): self
    {
        return new self("The [{$driver}] coach provider returned an empty response.");
    }

    public static function invalidJson(string $content): self
    {
        return new self('The coach response was not valid JSON: '.mb_strimwidth($content, 0, 300, '…'));
    }

    public static function unsupportedDriver(string $driver): self
    {
        return new self("The [{$driver}] coach driver is not implemented.");
    }
}
