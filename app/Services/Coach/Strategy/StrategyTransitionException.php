<?php

namespace App\Services\Coach\Strategy;

use App\Models\Strategy;
use RuntimeException;

/**
 * Raised when a strategy version transition cannot proceed — either the coach
 * authored an invalid revision, or the strategy being superseded is not the
 * active one (which would break the one-active-version-per-intention invariant).
 */
class StrategyTransitionException extends RuntimeException
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

    public static function notActive(Strategy $strategy): self
    {
        return new self(
            "Only an active strategy can be revised; version {$strategy->version} is [{$strategy->status}].",
        );
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $payload
     */
    public static function invalidRevision(array $errors, array $payload): self
    {
        $summary = $errors === [] ? 'unknown reason' : implode(' ', $errors);

        return new self(
            "The coach authored an invalid strategy revision: {$summary}",
            $errors,
            $payload,
        );
    }
}
