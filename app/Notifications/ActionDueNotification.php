<?php

namespace App\Notifications;

use App\Models\Occurrence;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The cue: an occasion's scheduled moment has arrived (SP2 fired it). Always
 * delivered in-app via the database channel and surfaced in the inbox; also
 * emailed when the user has chosen to hear about every cue.
 *
 * implements ShouldQueue so the SMTP round trip never runs inside the
 * scheduler's minute — actions:fire dispatches this and must stay fast. That
 * alone would queue every channel, including database, which would make the
 * in-app inbox depend on a running queue worker (it did not before this
 * branch, and must not start now). viaConnections() below pins database back
 * to the sync connection so only mail actually queues.
 */
class ActionDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Occurrence $occurrence)
    {
        $this->occurrence->loadMissing('action.intention');
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

    /**
     * Keeps the database channel synchronous despite ShouldQueue, so the
     * in-app inbox insert still needs no queue worker — only the mail
     * channel queues, satisfying the original motive (keeping SMTP out of
     * the scheduler's minute) without making the inbox depend on a worker.
     *
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $action = $this->occurrence->action;
        $loop = $action->intention;

        return (new MailMessage)
            ->subject($action->title)
            ->line("It's time for: {$action->title}")
            ->line("Loop: {$loop->title}")
            ->line("Cue: {$loop->cue}")
            ->action('Open PatYourSelf', route('loops.show', $loop->id))
            ->line('Manage your reminders: '.route('notifications.edit'));
    }

    /**
     * @return array{occurrence_id: int, action_id: int, intention_id: int, title: string, fired_at: ?string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            // The occasion is what the cue is about, and what answering it
            // marks read. action_id stays so an inbox entry can still be
            // grouped by the prescription it came from.
            'occurrence_id' => $this->occurrence->id,
            'action_id' => $this->occurrence->action_id,
            'intention_id' => $this->occurrence->action->intention_id,
            'title' => $this->occurrence->action->intention->title,
            'fired_at' => $this->occurrence->fired_at?->toIso8601String(),
        ];
    }
}
