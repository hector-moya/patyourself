<?php

namespace App\Listeners;

use App\Events\ActionFired;
use App\Notifications\ActionDueNotification;

/**
 * Delivers the in-app cue when an action fires: notifies the action's owner via
 * the database channel. ActionDueNotification implements ShouldQueue (for the
 * optional mail channel) but pins the database channel back to the sync
 * connection via viaConnections(), so this insert still needs no queue worker.
 */
class SendDueNotification
{
    public function handle(ActionFired $event): void
    {
        $action = $event->action;

        $action->intention->user->notify(new ActionDueNotification($action));
    }
}
