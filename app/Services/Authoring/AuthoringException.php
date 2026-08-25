<?php

namespace App\Services\Authoring;

use RuntimeException;

/**
 * Raised when an authored payload cannot be parsed into a valid DTO.
 */
class AuthoringException extends RuntimeException
{
    public static function emptyResponse(string $driver): self
    {
        return new self("The [{$driver}] coach provider returned an empty response.");
    }
}
