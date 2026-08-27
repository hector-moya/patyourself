<?php

namespace App\Actions;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Services\Authoring\AuthoredAction;
use App\Services\Authoring\AuthoredStrategy;
use App\Services\Scheduling\Recurrence;
use App\Services\Scheduling\Schedule;
use App\Services\Strategy\BehavioralChain;
use App\Services\Strategy\StrategyTransitionException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Starts a new experiment on a loop: supersedes the current strategy version
 * and creates the next one.
 *
 * History is never rewritten in place. Each transition records WHY it happened,
 * WHERE in the cue → craving → response → reward chain the new version
 * intervenes, and how long it is meant to run before it gets a verdict. This
 * action is the only place those writes happen.
 */
final class StartExperiment
{
    /**
     * @param  Strategy  $current  The active version being superseded; must be Strategy::STATUS_ACTIVE.
     * @param  AuthoredStrategy  $next  The hypothesis, authored in Claude and arriving through MCP.
     * @param  string  $changeReason  One of Strategy::CHANGE_REASONS.
     * @param  string|null  $supersededReason  Why the outgoing version is being replaced.
     * @param  int|null  $reviewAfterDays  Planned run length; null leaves the experiment open-ended.
     * @param  AuthoredAction|null  $revisedAction  The new action's cadence. Pass it to
     *                                              re-propose the action's cadence (title/schedule) for the new strategy; omit it
     *                                              (leave null) to inherit the prior action's schedule verbatim, only retitling
     *                                              from the new approach. This pass-vs-omit choice is the least guessable part
     *                                              of this API: passing null is not "no action" — an action is always created —
     *                                              it means "keep the old cadence."
     *
     * @throws StrategyTransitionException
     * @throws InvalidArgumentException
     */
    public function handle(
        Strategy $current,
        AuthoredStrategy $next,
        string $changeReason,
        ?string $supersededReason = null,
        ?int $reviewAfterDays = null,
        ?AuthoredAction $revisedAction = null,
    ): Strategy {
        if ($reviewAfterDays !== null && $reviewAfterDays < 0) {
            throw new InvalidArgumentException('reviewAfterDays cannot be negative.');
        }

        $this->guardActive($current);

        return DB::transaction(fn (): Strategy => $this->supersedeAndCreate(
            $current,
            $next,
            $changeReason,
            $supersededReason,
            $reviewAfterDays,
            $revisedAction,
        ));
    }

    /**
     * @throws StrategyTransitionException
     */
    private function guardActive(Strategy $current): void
    {
        if ($current->status !== Strategy::STATUS_ACTIVE) {
            throw StrategyTransitionException::notActive($current);
        }
    }

    private function supersedeAndCreate(
        Strategy $current,
        AuthoredStrategy $next,
        string $changeReason,
        ?string $supersededReason,
        ?int $reviewAfterDays,
        ?AuthoredAction $revisedAction,
    ): Strategy {
        $current->update([
            'status' => Strategy::STATUS_SUPERSEDED,
            'superseded_reason' => $supersededReason,
        ]);

        $nextVersion = (int) $current->intention->strategies()->max('version') + 1;

        $newStrategy = $current->intention->strategies()->create([
            'version' => $nextVersion,
            'status' => Strategy::STATUS_ACTIVE,
            'intervention_point' => $next->interventionPoint,
            'approach' => $next->approach,
            'rationale' => $next->rationale,
            'parent_strategy_id' => $current->id,
            'change_reason' => $changeReason,
            'review_at' => $reviewAfterDays === null ? null : now()->addDays($reviewAfterDays),
            'metadata' => array_filter([
                'previous_point' => $current->intervention_point,
                'direction' => BehavioralChain::direction(
                    $current->intervention_point,
                    $next->interventionPoint,
                ),
                'prompt_version' => $next->promptVersion,
            ], static fn ($value): bool => $value !== null),
        ]);

        $this->authorActionFor($current->intention, $newStrategy, $next, $revisedAction);

        return $newStrategy;
    }

    private function authorActionFor(Intention $intention, Strategy $strategy, AuthoredStrategy $next, ?AuthoredAction $revisedAction): void
    {
        $prior = $intention->activeAction;

        $intention->actions()
            ->where('status', '!=', Action::STATUS_ARCHIVED)
            ->update(['status' => Action::STATUS_ARCHIVED]);

        $action = $revisedAction;
        $timezone = $intention->user?->timezone ?? (string) config('app.timezone');

        if ($action !== null) {
            $recurrence = Recurrence::tryFromToken($action->recurrence);
            $scheduledFor = (new Schedule)->firstOccurrence(CarbonImmutable::now(), $action->time, $recurrence, $timezone);
            $title = $action->title;
            $metadata = array_filter(['schedule_kind' => $action->kind, 'anchor' => $action->anchor], static fn ($v): bool => $v !== null);
        } else {
            // Inherit the prior cadence; retitle from the new tactic.
            $recurrence = Recurrence::tryFromToken($prior?->recurrence);
            $scheduledFor = $this->inheritedAnchor($prior?->series_started_at?->toImmutable(), $recurrence, $timezone);
            $title = Str::limit($next->approach, 250, '');
            $metadata = array_filter([
                'schedule_kind' => $prior?->metadata['schedule_kind'] ?? null,
                'anchor' => $prior?->metadata['anchor'] ?? null,
                'inherited_from_action_id' => $prior?->id,
            ], static fn ($v): bool => $v !== null);
        }

        $strategy->actions()->create([
            'intention_id' => $intention->id,
            'title' => $title,
            'description' => $next->rationale,
            // Where this cadence begins. Materialisation walks forward from
            // here, so an inherited anchor is rolled to its next real slot
            // rather than copied verbatim — see inheritedAnchor().
            'series_started_at' => $scheduledFor,
            'recurrence' => $recurrence?->value,
            'status' => Action::STATUS_ACTIVE,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Where a revision that inherits the prior cadence should start counting
     * from.
     *
     * The anchor records where the prior action's cadence *began*, which for a
     * loop that has been running is arbitrarily far in the past. Copied over
     * verbatim it would hand the brand-new action the entire historical grid to
     * materialise — and every one of those occasions would be unlogged, because
     * the outcomes belong to the prior action's occurrences. A revision would
     * therefore manufacture a catch-up backlog out of days the user actually
     * completed. Roll it to the next real slot after now instead, which keeps
     * the cadence's phase (and stays DST-correct) rather than restarting it at
     * the moment of the revision. UpdateIntention::reanchorStaleActions()
     * applies the same treatment when a paused loop goes live.
     *
     * A future anchor is already ahead of the grid and is inherited untouched.
     *
     * Known residue: nextAfter() returns null for a one-off (no recurrence), so
     * a past one-off anchor is inherited as-is and leaves a single occasion
     * behind now. That is one row, it predates this branch, and collapsing it
     * would need a decision about what "repeat a one-off" even means — so it
     * stays as it was.
     */
    private function inheritedAnchor(?CarbonImmutable $priorAnchor, ?Recurrence $recurrence, string $timezone): ?CarbonImmutable
    {
        $now = CarbonImmutable::now();

        if ($priorAnchor === null || $priorAnchor->greaterThan($now)) {
            return $priorAnchor;
        }

        $schedule = new Schedule;

        // nextAfter() preserves the phase for a recurring cadence — same
        // weekday, same clock time, DST-correct. It returns null for a one-off,
        // which has no next slot to roll to; firstOccurrence() then puts it at
        // the same time of day on the next day it can happen.
        //
        // Falling back to the prior anchor instead would hand a brand-new
        // action a date in the past, and materialisation would turn that into an
        // unlogged occasion behind now — a miss the user never had the chance to
        // avoid. UpdateIntention::reanchorStaleActions() re-anchors the same way.
        return $schedule->nextAfter($priorAnchor, $now, $recurrence, $timezone)
            ?? $schedule->firstOccurrence(
                $now,
                $priorAnchor->setTimezone($timezone)->format('H:i'),
                $recurrence,
                $timezone,
            );
    }
}
