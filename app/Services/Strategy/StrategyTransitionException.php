<?php

namespace App\Services\Strategy;

use App\Models\Strategy;
use RuntimeException;

/**
 * Raised when a strategy version transition cannot proceed — either the
 * authored revision is invalid, or the strategy being superseded is not the
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

    public static function alreadyConcluded(Strategy $strategy): self
    {
        return new self("Strategy version {$strategy->version} was already concluded as [{$strategy->verdict}].");
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $payload
     */
    public static function invalidRevision(array $errors, array $payload): self
    {
        $summary = $errors === [] ? 'unknown reason' : implode(' ', $errors);

        return new self(
            "The authored strategy revision is invalid: {$summary}",
            $errors,
            $payload,
        );
    }
}
