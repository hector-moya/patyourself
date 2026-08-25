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

/**
 * The heart of the coaching loop: advancing a strategy to its next version.
 *
 * History is never rewritten in place. Each transition supersedes the current
 * (active) version and creates a new active one — recording WHY (stacked on a
 * success / restrategized on a user-stated failure), WHERE in the behavioural
 * chain it now intervenes, and which direction it moved. This action is the
 * only place these writes happen. The revision always arrives pre-authored;
 * this action does not decide what the next strategy should be.
 */
final class ReviseStrategy
{
    /**
     * The current strategy succeeded — stack toward a harder goal.
     *
     * @throws StrategyTransitionException
     */
    public function stackOnSuccess(Strategy $current, AuthoredStrategy $next, ?AuthoredAction $revisedAction = null): Strategy
    {
        $this->guardActive($current);

        return DB::transaction(fn (): Strategy => $this->supersedeAndCreate(
            $current,
            $next,
            Strategy::REASON_STACKED_ON_SUCCESS,
            supersededReason: null,
            revisedAction: $revisedAction,
        ));
    }

    /**
     * The current strategy failed — restrategize from the user-stated reason.
     *
     * @throws StrategyTransitionException
     */
    public function restrategizeOnFailure(Strategy $current, string $reason, AuthoredStrategy $next, ?AuthoredAction $revisedAction = null): Strategy
    {
        $this->guardActive($current);

        return DB::transaction(fn (): Strategy => $this->supersedeAndCreate(
            $current,
            $next,
            Strategy::REASON_RESTRATEGIZED_ON_FAILURE,
            supersededReason: $reason,
            revisedAction: $revisedAction,
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
            ->whereIn('status', [Action::STATUS_PENDING, Action::STATUS_ACTIVE])
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
            $scheduledFor = $prior?->scheduled_for;
            $recurrence = Recurrence::tryFromToken($prior?->recurrence);
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
            'scheduled_for' => $scheduledFor,
            'recurrence' => $recurrence?->value,
            'status' => Action::STATUS_PENDING,
            'metadata' => $metadata,
        ]);
    }
}
