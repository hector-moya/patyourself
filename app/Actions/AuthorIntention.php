<?php

namespace App\Actions;

use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Coach\Authoring\AuthoredIntention;
use App\Services\Coach\Authoring\IntentionAuthor;
use App\Services\Coach\Authoring\IntentionAuthoringException;
use App\Services\Coach\Exceptions\CoachException;
use Illuminate\Support\Facades\DB;

/**
 * Authors an Intention for a user and persists it. This is the only place the
 * authoring flow writes to the database: it asks the coach for a structured
 * Intention, then records the loop and seeds version 1 of its strategy.
 *
 * Validation happens in the author (before the transaction), so a malformed or
 * schema-invalid coach response throws and writes nothing.
 */
final readonly class AuthorIntention
{
    public function __construct(private IntentionAuthor $author) {}

    /**
     * @param  array<string, mixed>  $context  Optional extra signal for the prompt.
     *
     * @throws CoachException
     * @throws IntentionAuthoringException
     */
    public function handle(User $user, string $goal, array $context = []): Intention
    {
        $authored = $this->author->author($goal, $context);

        return DB::transaction(fn (): Intention => $this->persist($user, $authored));
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
            $intention->strategies()->create([
                'version' => 1,
                'status' => Strategy::STATUS_ACTIVE,
                'intervention_point' => $authored->strategy->interventionPoint,
                'approach' => $authored->strategy->approach,
                'rationale' => $authored->strategy->rationale,
                'change_reason' => Strategy::REASON_INITIAL,
            ]);

            $intention->setRelation('activeStrategy', $intention->activeStrategy()->first());
        }

        return $intention;
    }
}
