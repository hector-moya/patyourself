<?php

namespace App\Notifications;

use App\Models\Strategy;
use Illuminate\Notifications\Notification;

/**
 * Tells the user their habit's strategy was automatically revised by the coaching
 * closure (SP4). Delivered in-app via the database channel and surfaced in the
 * inbox alongside due cues — the `type` discriminator lets the inbox render it
 * distinctly. Email/push are future via() channels, as with ActionDueNotification.
 */
final class StrategyRevisedNotification extends Notification
{
    public function __construct(private readonly Strategy $strategy)
    {
        $this->strategy->loadMissing('intention');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{type:string, intention_id:int, strategy_id:int, change_reason:string, title:string, approach:string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'strategy_revised',
            'intention_id' => $this->strategy->intention_id,
            'strategy_id' => $this->strategy->id,
            'change_reason' => $this->strategy->change_reason,
            'title' => $this->strategy->intention->title,
            'approach' => $this->strategy->approach,
        ];
    }
}
