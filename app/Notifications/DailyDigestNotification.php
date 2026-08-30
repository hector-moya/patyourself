<?php

namespace App\Notifications;

use App\Services\Reminders\QuickLogLinks;
use App\Services\Scheduling\TodaysOccasion;
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
     * @param  Collection<int, TodaysOccasion>  $occasions
     */
    public function __construct(private readonly Collection $occasions) {}

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
        $count = $this->occasions->count();

        $mail = (new MailMessage)
            ->subject($count === 1 ? '1 thing today' : "{$count} things today")
            ->line('Here is what you are working on today.');

        foreach ($this->occasions as $occasion) {
            $when = $occasion->scheduledFor
                ? $occasion->scheduledFor->timezone($timezone)->format('g:ia')
                : 'when the cue happens';

            $mail->line("• {$occasion->action->title} — {$occasion->action->intention->title} ({$when})");

            // A cue-anchored action has no occurrence yet — logging it is what
            // creates one — so there is nothing to build a one-click link
            // against. It stays listed above, without links, until it fires.
            if ($occasion->occurrence !== null) {
                $links = QuickLogLinks::linksFor($occasion->occurrence);

                // Markdown link syntax, not a bare URL: Laravel's mail Markdown
                // environment has no autolink extension, so a bare URL in a
                // ->line() would render as plain text, not a clickable anchor.
                $mail->line("[Done]({$links['done']}) · [Didn't happen]({$links['skipped']})");
            }
        }

        return $mail->action('Open PatYourSelf', route('dashboard'))
            ->line('[Manage your reminders]('.route('notifications.edit').')');
    }
}
