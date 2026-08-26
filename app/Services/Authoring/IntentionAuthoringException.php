<?php

namespace App\Services\Authoring;

use RuntimeException;

/**
 * Raised when an authored payload parses as JSON but does not satisfy the
 * Intention schema (missing chain fields, bad enum, etc.). Distinct from
 * AuthoringException, which covers a payload missing required structure.
 */
class IntentionAuthoringException extends RuntimeException
{
    /**
     * @param  list<string>  $errors  Human-readable validation messages.
     * @param  array<string, mixed>  $payload  The decoded payload that failed.
     */
    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly array $payload = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $payload
     */
    public static function validationFailed(array $errors, array $payload): self
    {
        $summary = $errors === [] ? 'unknown reason' : implode(' ', $errors);

        return new self(
            "The coach authored an Intention that failed validation: {$summary}",
            $errors,
            $payload,
        );
    }
}
