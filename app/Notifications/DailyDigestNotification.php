<?php

namespace App\Notifications;

use App\Models\Action;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * The daily digest: everything the user owes today, in one email at their
 * chosen local time. Mail only — the in-app inbox already carries each cue
 * individually as it fires.
 */
class DailyDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Collection<int, Action>  $actions
     */
    public function __construct(private readonly Collection $actions) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $timezone = $notifiable->timezone ?? config('app.timezone');
        $count = $this->actions->count();

        $mail = (new MailMessage)
            ->subject($count === 1 ? '1 thing today' : "{$count} things today")
            ->line('Here is what you are working on today.');

        foreach ($this->actions as $action) {
            $when = $action->scheduled_for
                ? $action->scheduled_for->timezone($timezone)->format('g:ia')
                : 'when the cue happens';

            $mail->line("• {$action->title} — {$action->intention->title} ({$when})");
        }

        return $mail->action('Open PatYourSelf', route('dashboard'))
            ->line('Manage your reminders: '.route('notifications.edit'));
    }
}
