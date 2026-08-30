<?php

namespace Tests\Feature\Notifications;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use App\Notifications\ActionDueNotification;
use App\Notifications\DailyDigestNotification;
use App\Services\Scheduling\TodaysOccasion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reminder mail is the app's only unauthenticated write, so a link that
 * merely looks right is not good enough — every assertion here follows the
 * generated link through the real route rather than pattern-matching its
 * string, per QuickLogController's own test suite.
 *
 * Each URL is also required to sit inside a real `href="..."` attribute, not
 * merely appear somewhere in the rendered body: a plain-text URL (no anchor
 * tag at all) would satisfy a bare substring match just as readily as a real
 * link, which is exactly how this suite once passed against mail with no
 * clickable links in it.
 */
class QuickLogLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Following a generated link back through $this->get() renders the
        // quick-log Blade view, which carries @vite — this suite must stay
        // green on a fresh clone that has never run `npm run build`.
        $this->withoutVite();
    }

    private function occurrence(): Occurrence
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop, 'intention')->create(['status' => Action::STATUS_ACTIVE]);

        return $action->occurrences()->create([
            'scheduled_for' => now()->subHour(),
            'fired_at' => now()->subHour(),
        ]);
    }

    public function test_the_cue_mail_carries_a_working_done_link(): void
    {
        $occurrence = $this->occurrence();
        $user = $occurrence->action->intention->user;
        $user->update(['email_reminders' => User::EMAIL_REMINDERS_EVERY_CUE]);

        $mail = (new ActionDueNotification($occurrence))->toMail($user);
        $rendered = (string) $mail->render();

        // Matching only inside href="..." is what tells a real <a href> apart
        // from the URL merely appearing as plain body text.
        preg_match('#href="(https?://[^"]+/o/\d+/completed[^"]*)"#', $rendered, $matches);
        $this->assertNotEmpty($matches, 'The cue mail should carry a one-click Done link inside a real href.');

        $this->get(html_entity_decode($matches[1]))->assertOk();
        $this->assertDatabaseHas('action_logs', ['occurrence_id' => $occurrence->id, 'outcome' => 'completed']);
    }

    public function test_the_cue_mail_carries_a_working_skipped_link(): void
    {
        $occurrence = $this->occurrence();
        $user = $occurrence->action->intention->user;
        $user->update(['email_reminders' => User::EMAIL_REMINDERS_EVERY_CUE]);

        $mail = (new ActionDueNotification($occurrence))->toMail($user);
        $rendered = (string) $mail->render();

        preg_match('#href="(https?://[^"]+/o/\d+/skipped[^"]*)"#', $rendered, $matches);
        $this->assertNotEmpty($matches, 'The cue mail should carry a one-click Didn\'t-happen link inside a real href.');

        $this->get(html_entity_decode($matches[1]))->assertOk();
        $this->assertDatabaseHas('action_logs', ['occurrence_id' => $occurrence->id, 'outcome' => 'skipped']);
    }

    public function test_the_cue_mail_has_no_one_click_failed_link(): void
    {
        $occurrence = $this->occurrence();
        $user = $occurrence->action->intention->user;
        $user->update(['email_reminders' => User::EMAIL_REMINDERS_EVERY_CUE]);

        $mail = (new ActionDueNotification($occurrence))->toMail($user);
        $rendered = (string) $mail->render();

        preg_match('#href="(https?://[^"]+/o/\d+/failed[^"]*)"#', $rendered, $matches);
        $this->assertEmpty($matches, 'A failed outcome must never be a one-click link — it needs the user\'s own reason.');

        // The failure path must still be a real link into the app — plain
        // text with no anchor would leave the mail with no clickable route
        // into the app for this outcome at all.
        $loop = $occurrence->action->intention;
        preg_match('#href="('.preg_quote(route('loops.show', $loop->id), '#').')"#', $rendered, $failureLink);
        $this->assertNotEmpty($failureLink, 'The failure outcome must deep-link into the app as a real anchor, not plain text.');
    }

    public function test_the_digest_carries_a_working_done_link_per_row_not_just_the_first(): void
    {
        $occurrenceOne = $this->occurrence();
        $occurrenceTwo = $this->occurrence();
        $user = $occurrenceOne->action->intention->user;

        $occasions = collect([
            new TodaysOccasion(
                action: $occurrenceOne->action,
                occurrence: $occurrenceOne,
                scheduledFor: $occurrenceOne->scheduled_for->toImmutable(),
                due: TodaysOccasion::DUE_NOW,
            ),
            new TodaysOccasion(
                action: $occurrenceTwo->action,
                occurrence: $occurrenceTwo,
                scheduledFor: $occurrenceTwo->scheduled_for->toImmutable(),
                due: TodaysOccasion::DUE_NOW,
            ),
        ]);

        $mail = (new DailyDigestNotification($occasions))->toMail($user);
        $rendered = (string) $mail->render();

        preg_match_all('#href="(https?://[^"]+/o/\d+/completed[^"]*)"#', $rendered, $matches);
        $this->assertCount(2, $matches[1], 'Each digest row should carry its own Done link inside a real href.');

        $this->get(html_entity_decode($matches[1][0]))->assertOk();
        $this->assertDatabaseHas('action_logs', ['occurrence_id' => $occurrenceOne->id, 'outcome' => 'completed']);

        $this->get(html_entity_decode($matches[1][1]))->assertOk();
        $this->assertDatabaseHas('action_logs', ['occurrence_id' => $occurrenceTwo->id, 'outcome' => 'completed']);
    }

    public function test_the_digest_skips_links_for_a_cue_anchored_row_with_no_occurrence_yet(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop, 'intention')->create([
            'status' => Action::STATUS_ACTIVE,
            'series_started_at' => null,
            'recurrence' => null,
        ]);

        $occasions = collect([
            new TodaysOccasion(
                action: $action,
                occurrence: null,
                scheduledFor: null,
                due: TodaysOccasion::ANCHORED,
            ),
        ]);

        $mail = (new DailyDigestNotification($occasions))->toMail($user);
        $rendered = (string) $mail->render();

        preg_match('#https?://[^"\s]+/o/\d+/(completed|skipped)[^"\s]*#', $rendered, $matches);
        $this->assertEmpty($matches, 'An action with no occurrence yet has nothing to log against.');
    }
}
