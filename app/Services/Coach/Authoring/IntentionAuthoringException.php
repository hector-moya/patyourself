<?php

namespace App\Services\Coach\Authoring;

use RuntimeException;

/**
 * Raised when the LLM returns parseable JSON that does not satisfy the
 * Intention schema (missing chain fields, bad enum, etc.). Distinct from
 * CoachException, which covers transport / unparseable-output failures.
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
