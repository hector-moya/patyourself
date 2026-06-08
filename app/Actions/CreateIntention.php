<?php

namespace App\Actions;

use App\Models\Intention;
use App\Models\User;

/**
 * Persists a manually-authored loop for a user. The LLM path lives in
 * {@see AuthorIntention}; this is the human-entered counterpart, and the single
 * place the manual create flow writes to the database. Both the web and API
 * controllers call into it so the two surfaces can never drift.
 */
final readonly class CreateIntention
{
    /**
     * @param  array<string, mixed>  $data  Validated loop fields.
     */
    public function handle(User $user, array $data): Intention
    {
        return $user->intentions()->create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'status' => $data['status'] ?? Intention::STATUS_ACTIVE,
            'cue' => $data['cue'],
            'craving' => $data['craving'],
            'response' => $data['response'],
            'reward' => $data['reward'],
            'metadata' => ['authored_by' => 'user'],
        ]);
    }
}
