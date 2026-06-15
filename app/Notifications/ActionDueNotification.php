<?php

namespace App\Notifications;

use App\Models\Action;
use Illuminate\Notifications\Notification;

/**
 * The cue: an action's scheduled moment has arrived (SP2 fired it). Delivered
 * in-app via the database channel and surfaced in the inbox. Email and web push
 * are future channels that attach here by extending via() — no other plumbing.
 */
class ActionDueNotification extends Notification
{
    public function __construct(private readonly Action $action)
    {
        $this->action->loadMissing('intention');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{action_id: int, intention_id: int, title: string, fired_at: ?string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'action_id' => $this->action->id,
            'intention_id' => $this->action->intention_id,
            'title' => $this->action->intention->title,
            'fired_at' => $this->action->metadata['fired_at'] ?? null,
        ];
    }
}
