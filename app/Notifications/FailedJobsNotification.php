<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Tells the owner that background jobs have failed since the last check.
 *
 * Deliberately NOT ShouldQueue: an alert about a broken queue must not ride
 * that queue, or the failure that matters most would be the one that never
 * gets reported. Omitting ShouldQueue is what makes NotificationSender call
 * sendNow() directly — a viaConnections() pin (the trick ActionDueNotification
 * uses) only takes effect for notifications that *do* implement ShouldQueue,
 * so it would be dead code here and is deliberately not declared.
 */
class FailedJobsNotification extends Notification
{
    /** A full stack trace can run 5-50 KB; CommonMark would collapse it into one unreadable paragraph. */
    private const EXCEPTION_PREVIEW_LENGTH = 1000;

    public function __construct(
        public readonly int $count,
        public readonly ?string $latestException,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $noun = $this->count === 1 ? 'job has' : 'jobs have';

        return (new MailMessage)
            ->subject("PatYourSelf: {$this->count} background {$noun} failed")
            ->line("{$this->count} background {$noun} failed since the last check.")
            ->line('Reminders and digests ride that queue, so some may not have been delivered.')
            ->line('Most recent exception:')
            ->line(Str::limit($this->latestException ?? 'No exception was recorded.', self::EXCEPTION_PREVIEW_LENGTH));
    }
}
