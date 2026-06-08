<?php

namespace App\Actions;

use App\Models\Intention;

/**
 * Updates an existing loop from validated input. Only keys present in the
 * payload are touched, so partial (PATCH-style) edits leave the rest intact.
 * The only place the manual update flow writes to the database.
 */
final readonly class UpdateIntention
{
    /**
     * @param  array<string, mixed>  $data  Validated subset of loop fields.
     */
    public function handle(Intention $intention, array $data): Intention
    {
        $fields = array_intersect_key($data, array_flip([
            'title',
            'description',
            'type',
            'status',
            'cue',
            'craving',
            'response',
            'reward',
        ]));

        $intention->update($fields);

        return $intention;
    }
}
