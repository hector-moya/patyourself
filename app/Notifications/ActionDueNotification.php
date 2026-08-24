<?php

namespace App\Notifications;

use App\Models\Action;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The cue: an action's scheduled moment has arrived (SP2 fired it). Always
 * delivered in-app via the database channel and surfaced in the inbox; also
 * emailed when the user has chosen to hear about every cue.
 *
 * Queued so the SMTP round trip never runs inside the scheduler's minute —
 * actions:fire dispatches this and must stay fast.
 */
class ActionDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Action $action)
    {
        $this->action->loadMissing('intention');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable instanceof User && $notifiable->email_reminders === User::EMAIL_REMINDERS_EVERY_CUE) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $loop = $this->action->intention;

        return (new MailMessage)
            ->subject($this->action->title)
            ->line("It's time for: {$this->action->title}")
            ->line("Loop: {$loop->title}")
            ->line("Cue: {$loop->cue}")
            ->action('Open PatYourSelf', route('intentions.show', $loop->id))
            ->line('Manage your reminders: '.route('notifications.edit'));
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
