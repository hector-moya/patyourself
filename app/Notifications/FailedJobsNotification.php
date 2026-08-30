<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the owner that background jobs have failed since the last check.
 *
 * Deliberately NOT queued, and pinned to the sync connection: this is an alert
 * about the queue, so routing it through the queue would mean the failure that
 * matters most is the one that never gets reported.
 */
class FailedJobsNotification extends Notification
{
    use Queueable;

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

    /**
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['mail' => 'sync'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $noun = $this->count === 1 ? 'job has' : 'jobs have';

        return (new MailMessage)
            ->subject("PatYourSelf: {$this->count} background {$noun} failed")
            ->line("{$this->count} background {$noun} failed since the last check.")
            ->line('Reminders and digests ride that queue, so some may not have been delivered.')
            ->line('Most recent exception:')
            ->line($this->latestException ?? 'No exception was recorded.');
    }
}
