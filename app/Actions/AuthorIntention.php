<?php

namespace App\Actions;

use App\Ai\Agents\IntentionAuthor;
use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Coach\Authoring\AuthoredAction;
use App\Services\Coach\Authoring\AuthoredIntention;
use App\Services\Coach\Exceptions\CoachException;
use App\Services\Scheduling\Recurrence;
use App\Services\Scheduling\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Authors an Intention for a user and persists it. This is the only place the
 * authoring flow writes to the database: it asks the IntentionAuthor agent for a
 * structured Intention, then records the loop and seeds version 1 of its strategy.
 *
 * Validation happens before the transaction (in AuthoredIntention::fromStructured),
 * so a malformed or schema-invalid agent response throws and writes nothing.
 */
final readonly class AuthorIntention
{
    /**
     * @param  array<string, mixed>  $context  Optional extra signal for the prompt.
     * @param  AuthoredIntention|null  $authored  A pre-authored loop (e.g. from the
     *                                            chat flow); when null the agent authors one.
     *
     * @throws CoachException
     */
    public function handle(User $user, string $goal, array $context = [], ?AuthoredIntention $authored = null): Intention
    {
        if ($authored === null) {
            $response = (new IntentionAuthor)->prompt($this->userPrompt($goal, $context));
            $authored = AuthoredIntention::fromStructured(
                $response->structured,
                $response->meta->model ?? 'unknown',
                IntentionAuthor::PROMPT_VERSION,
            );
        }

        return DB::transaction(fn (): Intention => $this->persist($user, $authored));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function userPrompt(string $goal, array $context): string
    {
        $prompt = "The user wants help with this habit:\n\n".trim($goal);

        if ($context !== []) {
            $prompt .= "\n\nAdditional context:\n".json_encode(
                $context,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            );
        }

        return $prompt;
    }

    private function persist(User $user, AuthoredIntention $authored): Intention
    {
        $intention = Intention::create([
            'user_id' => $user->id,
            'title' => $authored->title,
            'description' => $authored->description,
            'type' => $authored->type,
            'status' => Intention::STATUS_ACTIVE,
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
            'scheduled_for' => $scheduledFor,
            'recurrence' => $recurrence?->value,
            'status' => Action::STATUS_PENDING,
            'metadata' => array_filter([
                'schedule_kind' => $action->kind,
                'anchor' => $action->anchor,
            ], static fn ($value): bool => $value !== null),
        ]);
    }
}
