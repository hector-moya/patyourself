<?php

namespace App\Actions;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Services\Authoring\AuthoredAction;
use App\Services\Scheduling\Recurrence;
use App\Services\Scheduling\Schedule;
use App\Services\Strategy\StrategyTransitionException;
use Carbon\CarbonImmutable;

/**
 * Adds an action to a loop's current experiment, after the loop already exists.
 *
 * Without this, a loop's action layer is frozen at creation: the only other
 * writers are {@see PersistAuthoredIntention} (loop creation) and
 * {@see StartExperiment} (a new version), so splitting one action into two —
 * one per meal, say — meant starting an experiment the user did not want.
 *
 * The action binds to the loop's active strategy, so every outcome it later
 * carries attributes to the experiment that was running when it was made.
 */
final readonly class CreateAction
{
    public function __construct(private Schedule $schedule) {}

    /**
     * @throws StrategyTransitionException when the loop has no active version to attach to.
     */
    public function handle(Intention $loop, AuthoredAction $authored): Action
    {
        $strategy = $loop->activeStrategy;

        if (! $strategy instanceof Strategy) {
            throw new StrategyTransitionException(
                'A loop needs an active strategy version before an action can be added to it.',
            );
        }

        $timezone = $loop->user?->timezone ?? (string) config('app.timezone');
        $recurrence = Recurrence::tryFromToken($authored->recurrence);

        $scheduledFor = $this->schedule->firstOccurrence(
            CarbonImmutable::now(),
            $authored->time,
            $recurrence,
            $timezone,
        );

        return $loop->actions()->create([
            'strategy_id' => $strategy->id,
            'title' => $authored->title,
            'description' => $authored->description,
            'scheduled_for' => $scheduledFor,
            // Where this cadence begins. Null for a cue-anchored action, which
            // has no schedule and so materialises no occasions.
            'series_started_at' => $scheduledFor,
            'recurrence' => $recurrence?->value,
            'status' => Action::STATUS_PENDING,
            'metadata' => array_filter([
                'schedule_kind' => $authored->kind,
                'anchor' => $authored->anchor,
            ], static fn ($value): bool => $value !== null),
        ]);
    }
}
