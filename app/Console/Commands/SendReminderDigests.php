<?php

namespace App\Console\Commands;

use App\Services\Reminders\DigestDispatcher;
use Illuminate\Console\Command;

/**
 * Sends the daily reminder digest to every user whose local digest time has
 * arrived and who has not had one today. The scheduler runs this every minute
 * (see routes/console.php); all logic lives in the dispatcher so it can be
 * feature-tested directly.
 */
class SendReminderDigests extends Command
{
    protected $signature = 'reminders:digest';

    protected $description = 'Send the daily reminder digest to users whose local digest time has arrived';

    public function handle(DigestDispatcher $dispatcher): int
    {
        $sent = $dispatcher->dispatchDue();

        $this->components->info("Sent {$sent} digest(s).");

        return self::SUCCESS;
    }
}
