<?php

namespace App\Actions;

use App\Models\Strategy;
use App\Services\Strategy\StrategyTransitionException;
use InvalidArgumentException;

/**
 * Ends an experiment with a verdict.
 *
 * Concluding is not superseding: a version concluded as `worked` stays active
 * and keeps running. Only {@see StartExperiment} supersedes, and it does so
 * when the *next* experiment begins.
 */
final readonly class ConcludeExperiment
{
    /**
     * @param  string  $verdict  One of Strategy::VERDICTS.
     *
     * @throws InvalidArgumentException|StrategyTransitionException
     */
    public function handle(Strategy $strategy, string $verdict, ?string $note = null): Strategy
    {
        if (! in_array($verdict, Strategy::VERDICTS, strict: true)) {
            throw new InvalidArgumentException("[{$verdict}] is not a valid experiment verdict.");
        }

        if ($strategy->isConcluded()) {
            throw StrategyTransitionException::alreadyConcluded($strategy);
        }

        $strategy->update([
            'verdict' => $verdict,
            'verdict_note' => $note,
            'review_at' => null,
        ]);

        return $strategy->refresh();
    }
}
