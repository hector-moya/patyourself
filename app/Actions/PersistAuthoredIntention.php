<?php

namespace App\Actions;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Authoring\AuthoredAction;
use App\Services\Authoring\AuthoredIntention;
use App\Services\Scheduling\Recurrence;
use App\Services\Scheduling\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Persists an already-authored loop: the intention, version 1 of its strategy,
 * and its first action, in one transaction.
 *
 * The authoring itself happens in Claude and arrives through the MCP create-loop
 * tool as a validated {@see AuthoredIntention}. This class makes no model call.
 */
final readonly class PersistAuthoredIntention
{
    public function handle(
        User $user,
        AuthoredIntention $authored,
        string $status = Intention::STATUS_ACTIVE,
    ): Intention {
        return DB::transaction(fn (): Intention => $this->persist($user, $authored, $status));
    }

    private function persist(User $user, AuthoredIntention $authored, string $status): Intention
    {
        $intention = Intention::create([
            'user_id' => $user->id,
            'title' => $authored->title,
            'description' => $authored->description,
            'type' => $authored->type,
            'status' => $status,
            'cue' => $authored->cue,
            'craving' => $authored->craving,
            'response' => $authored->response,
            'reward' => $authored->reward,
            'metadata' => $authored->metadata(),
        ]);

        if ($authored->strategy !== null) {
            $strategy = $intention->strategies()->create([
                'version' => 1,
                'status' => Strategy::STATUS_ACTIVE,
                'intervention_point' => $authored->strategy->interventionPoint,
                'approach' => $authored->strategy->approach,
                'rationale' => $authored->strategy->rationale,
                'change_reason' => Strategy::REASON_INITIAL,
                'metadata' => array_filter(['prompt_version' => $authored->promptVersion]),
            ]);

            $intention->setRelation('activeStrategy', $intention->activeStrategy()->first());

            if ($authored->action !== null) {
                $this->persistAction($intention, $strategy, $user, $authored->action);
            }
        }

        return $intention;
    }

    private function persistAction(Intention $intention, Strategy $strategy, User $user, AuthoredAction $action): void
    {
        $timezone = $user->timezone ?? (string) config('app.timezone');
        $recurrence = Recurrence::tryFromToken($action->recurrence);

        $scheduledFor = (new Schedule)->firstOccurrence(
            CarbonImmutable::now(),
            $action->time,
            $recurrence,
            $timezone,
        );

        $intention->actions()->create([
            'strategy_id' => $strategy->id,
            'title' => $action->title,
            'description' => $action->description,
            // Where this cadence begins, and what materialisation walks
            // forward from, so an action without it never produces an
            // occurrence and drops out of every check-in.
            'series_started_at' => $scheduledFor,
            'recurrence' => $recurrence?->value,
            'status' => Action::STATUS_ACTIVE,
            'metadata' => array_filter([
                'schedule_kind' => $action->kind,
                'anchor' => $action->anchor,
            ], static fn ($value): bool => $value !== null),
        ]);
    }
}
