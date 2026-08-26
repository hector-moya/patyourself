<?php

namespace App\Console\Commands;

use App\Models\Intention;
use App\Models\User;
use App\Services\Scheduling\MaterialiseOccurrences;
use App\Services\Scheduling\TriggerEngine;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds today's grid, then delivers the cue for every occasion whose moment
 * has arrived. The scheduler runs this every minute (see routes/console.php).
 *
 * Materialising here is what lets the engine read rather than compute: a cron's
 * read is still a read, and the "never as a side effect of a write" invariant
 * is about logging an outcome, which must never conjure occasions the check-in
 * then asks about.
 */
class FireDueActions extends Command
{
    protected $signature = 'actions:fire';

    protected $description = 'Deliver the cue for every occasion whose moment has arrived';

    public function handle(MaterialiseOccurrences $materialise, TriggerEngine $engine): int
    {
        User::query()
            ->whereHas('intentions', fn (Builder $query) => $query->where('status', Intention::STATUS_ACTIVE))
            ->cursor()
            ->each(fn (User $user) => $materialise->forUser($user));

        $fired = $engine->fireDueOccurrences();

        $this->components->info("Fired {$fired} cue(s).");

        return self::SUCCESS;
    }
}
