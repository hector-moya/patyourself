<?php

namespace App\Console\Commands;

use App\Services\Alerts\FailedJobsAlert;
use Illuminate\Console\Command;

/**
 * Hourly check for failed background jobs. All logic lives in the service so it
 * can be feature-tested directly, matching SendReminderDigests.
 */
class AlertFailedJobs extends Command
{
    protected $signature = 'jobs:alert-failed';

    protected $description = 'Mail the owner if background jobs have failed since the last check';

    public function handle(FailedJobsAlert $alert): int
    {
        $reported = $alert->sendIfAny();

        $this->components->info(
            $reported === 0 ? 'No new failed jobs.' : "Reported {$reported} failed job(s).",
        );

        return self::SUCCESS;
    }
}
