<?php

namespace App\Actions;

use App\Models\Intention;

/**
 * Permanently removes a loop and its dependent rows (strategies, actions,
 * summaries cascade at the database level). The only place the delete flow
 * writes to the database.
 */
final readonly class DeleteIntention
{
    public function handle(Intention $intention): void
    {
        $intention->delete();
    }
}
