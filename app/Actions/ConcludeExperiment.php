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
 *
 * `review_at` is deliberately left alone. Clearing it was redundant —
 * {@see Strategy::isUnderReview()} short-circuits on `! isConcluded()`, so the
 * verdict is already what ends the review — and it was destructive, because
 * {@see Strategy::plannedDays()} derives entirely from `review_at` and there is
 * no `concluded_at` to fall back on. Nulling it erased the only record of how
 * long the experiment was planned to run.
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
        ]);

        return $strategy->refresh();
    }
}
