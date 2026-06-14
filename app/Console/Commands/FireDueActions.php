<?php

namespace App\Console\Commands;

use App\Services\Scheduling\TriggerEngine;
use Illuminate\Console\Command;

/**
 * Scans for actions whose scheduled fire time has arrived and transitions them
 * pending -> active so they surface as live to-dos. The scheduler runs this
 * every minute (see routes/console.php). A thin wrapper: all logic lives in the
 * TriggerEngine service so it can be feature-tested directly.
 */
class FireDueActions extends Command
{
    protected $signature = 'actions:fire';

    protected $description = 'Fire due actions (pending -> active) for the trigger engine';

    public function handle(TriggerEngine $engine): int
    {
        $fired = $engine->fireDueActions();

        $this->components->info("Fired {$fired} action(s).");

        return self::SUCCESS;
    }
}
