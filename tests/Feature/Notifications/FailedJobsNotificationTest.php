<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\FailedJobsNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\TestCase;

class FailedJobsNotificationTest extends TestCase
{
    public function test_it_names_the_count_and_the_most_recent_exception(): void
    {
        $mail = (new FailedJobsNotification(3, 'RuntimeException: the worker died'))
            ->toMail(new User)->render();

        $this->assertStringContainsString('3 background jobs have failed', $mail);
        $this->assertStringContainsString('RuntimeException: the worker died', $mail);
    }

    public function test_it_reports_no_exception_gracefully(): void
    {
        $mail = (new FailedJobsNotification(1, null))->toMail(new User)->render();

        $this->assertStringContainsString('No exception was recorded.', $mail);
    }

    /**
     * A full stack trace is typically 5-50 KB. Mailed whole into a single
     * `MailMessage::line()`, CommonMark collapses it into one unreadable
     * paragraph. It must be cut down to something a reader can actually use.
     */
    public function test_a_long_exception_trace_is_truncated_before_it_reaches_the_mail(): void
    {
        $headline = 'RuntimeException: the worker died';
        $frames = collect(range(1, 100))
            ->map(fn (int $i): string => "#{$i} /app/Some/Very/Long/Namespaced/Path/To/AClass.php({$i}): AClass->aMethod()")
            ->implode("\n");
        $trace = "{$headline}\n{$frames}";

        $mail = (new FailedJobsNotification(1, $trace))->toMail(new User)->render();

        $this->assertStringContainsString($headline, $mail);
        // A frame this far into a 100-frame trace must have been cut off.
        $this->assertStringNotContainsString('#99 ', $mail);
    }

    /** Deliberately not ShouldQueue: see the class docblock. */
    public function test_it_is_not_a_queueable_notification(): void
    {
        $this->assertNotInstanceOf(ShouldQueue::class, new FailedJobsNotification(1, 'x'));
    }
}
