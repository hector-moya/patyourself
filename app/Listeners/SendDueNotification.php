<?php

namespace App\Listeners;

use App\Events\ActionFired;
use App\Notifications\ActionDueNotification;

/**
 * Delivers the in-app cue when an action fires: notifies the action's owner via
 * the database channel. Synchronous — a single insert; no queue worker needed.
 */
class SendDueNotification
{
    public function handle(ActionFired $event): void
    {
        $action = $event->action;

        $action->intention->user->notify(new ActionDueNotification($action));
    }
}
