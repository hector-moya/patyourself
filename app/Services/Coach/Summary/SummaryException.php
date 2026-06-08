<?php

namespace App\Services\Coach\Summary;

use RuntimeException;

/**
 * Raised when the LLM returns a rolling-summary payload that does not satisfy
 * the schema (e.g. missing content).
 */
class SummaryException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $payload
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
    public static function invalid(array $errors, array $payload): self
    {
        $summary = $errors === [] ? 'unknown reason' : implode(' ', $errors);

        return new self(
            "The coach authored an invalid rolling summary: {$summary}",
            $errors,
            $payload,
        );
    }
}
