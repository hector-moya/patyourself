<?php

namespace App\Listeners;

use App\Events\OccurrenceFired;
use App\Notifications\ActionDueNotification;

/**
 * Delivers the in-app cue when an occasion fires: notifies the action's owner
 * via the database channel. ActionDueNotification implements ShouldQueue (for
 * the optional mail channel) but pins the database channel back to the sync
 * connection via viaConnections(), so this insert still needs no queue worker.
 */
class SendDueNotification
{
    public function handle(OccurrenceFired $event): void
    {
        $event->occurrence->action->intention->user->notify(
            new ActionDueNotification($event->occurrence),
        );
    }
}
