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
     * @param  AuthoredStrategy  $next  The hypothesis, authored in Claude and arriving through MCP.
     * @param  string  $changeReason  One of Strategy::CHANGE_REASONS.
     * @param  string|null  $supersededReason  Why the outgoing version is being replaced.
     * @param  int|null  $reviewAfterDays  Planned run length; null leaves the experiment open-ended.
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
