<?php

namespace Tests\Feature\Actions;

use App\Actions\WriteReflection;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\Summary;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Writing the rolling narrative.
 *
 * The load-bearing part is the provenance: the coach supplies only the words,
 * and the app works out which window they cover and how many occasions sit in
 * it. A reflection that could name its own window could claim to have read
 * evidence it never looked at.
 */
class WriteReflectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-27 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function loop(User $user, ?CarbonImmutable $versionStartedAt = null): Intention
    {
        $loop = Intention::factory()->for($user)->create([
            'created_at' => CarbonImmutable::parse('2026-07-01 09:00:00'),
        ]);

        if ($versionStartedAt !== null) {
            Strategy::factory()->for($loop)->create([
                'version' => 1,
                'status' => Strategy::STATUS_ACTIVE,
                'created_at' => $versionStartedAt,
            ]);
        }

        return $loop->refresh();
    }

    /** An outcome whose *occasion* sits at $occurredAt, logged at $loggedAt. */
    private function outcomeAt(Intention $loop, CarbonImmutable $occurredAt, ?CarbonImmutable $loggedAt = null): ActionLog
    {
        $action = Action::factory()->for($loop)->create([
            'series_started_at' => $occurredAt,
            'recurrence' => null,
        ]);

        $occurrence = Occurrence::factory()->for($action)->create(['scheduled_for' => $occurredAt]);

        return ActionLog::factory()->for($action)->for($occurrence)->create([
            'user_id' => $loop->user_id,
            'logged_at' => $loggedAt ?? $occurredAt,
        ]);
    }

    public function test_it_writes_the_narrative_verbatim(): void
    {
        $user = User::factory()->create();
        $loop = $this->loop($user, CarbonImmutable::parse('2026-08-20 09:00:00'));

        // Padded and mixed-case on purpose: a narrative that arrives already
        // tidy cannot tell a verbatim implementation from a trimming one.
        $content = '  the pause HOLDS at dinner, and falls apart at lunch.  ';

        $summary = app(WriteReflection::class)->handle($loop, $content);

        $this->assertSame($content, $summary->content);
        $this->assertSame(Summary::SCOPE_INTENTION, $summary->scope);
        $this->assertSame($loop->id, $summary->intention_id);
        $this->assertSame($user->id, $summary->user_id);
        $this->assertDatabaseHas('summaries', ['id' => $summary->id, 'content' => $content]);
    }

    public function test_the_first_reflection_starts_at_the_active_versions_start(): void
    {
        $user = User::factory()->create();
        $versionStartedAt = CarbonImmutable::parse('2026-08-20 09:00:00');
        $loop = $this->loop($user, $versionStartedAt);

        $summary = app(WriteReflection::class)->handle($loop, 'holding so far');

        $this->assertTrue($summary->window_start->equalTo($versionStartedAt));
        $this->assertTrue($summary->window_end->equalTo(CarbonImmutable::parse('2026-08-27 12:00:00')));
    }

    public function test_a_loop_with_no_active_version_starts_at_the_loops_own_beginning(): void
    {
        $user = User::factory()->create();
        $loop = $this->loop($user);

        $summary = app(WriteReflection::class)->handle($loop, 'nothing tried yet');

        // Reflecting before the first experiment is written is legitimate, not
        // an error.
        $this->assertTrue($summary->window_start->equalTo(CarbonImmutable::parse('2026-07-01 09:00:00')));
    }

    public function test_a_later_reflection_starts_where_the_previous_one_ended(): void
    {
        $user = User::factory()->create();
        $loop = $this->loop($user, CarbonImmutable::parse('2026-08-01 09:00:00'));

        Summary::factory()->for($loop)->create([
            'user_id' => $user->id,
            'scope' => Summary::SCOPE_INTENTION,
            'window_end' => CarbonImmutable::parse('2026-08-20 09:00:00'),
        ]);

        $summary = app(WriteReflection::class)->handle($loop, 'since the last look');

        $this->assertTrue($summary->window_start->equalTo(CarbonImmutable::parse('2026-08-20 09:00:00')));
    }

    public function test_it_counts_the_occasions_inside_the_window(): void
    {
        $user = User::factory()->create();
        $loop = $this->loop($user, CarbonImmutable::parse('2026-08-20 09:00:00'));

        $this->outcomeAt($loop, CarbonImmutable::parse('2026-08-21 19:00:00'));
        $this->outcomeAt($loop, CarbonImmutable::parse('2026-08-23 19:00:00'));
        // Before the window: belongs to whatever came earlier.
        $this->outcomeAt($loop, CarbonImmutable::parse('2026-08-10 19:00:00'));

        $summary = app(WriteReflection::class)->handle($loop, 'two occasions in');

        $this->assertSame(2, $summary->events_count);
    }

    /**
     * The case that separates counting by occasion from counting by logged_at.
     * A catch-up typed today about an occasion from before the window belongs to
     * the earlier window — the narrative covers occasions, not typing sessions.
     */
    public function test_it_counts_by_occasion_not_by_when_the_outcome_was_typed(): void
    {
        $user = User::factory()->create();
        $loop = $this->loop($user, CarbonImmutable::parse('2026-08-20 09:00:00'));

        $this->outcomeAt(
            $loop,
            CarbonImmutable::parse('2026-08-10 19:00:00'),
            CarbonImmutable::parse('2026-08-27 11:00:00'),
        );

        $summary = app(WriteReflection::class)->handle($loop, 'caught up on an old one');

        $this->assertSame(0, $summary->events_count);
    }

    /**
     * The window is half-open: an occasion sitting exactly on the boundary
     * belongs to the earlier window, which already reported it.
     */
    public function test_an_occasion_on_the_boundary_is_not_counted_twice(): void
    {
        $user = User::factory()->create();
        $loop = $this->loop($user, CarbonImmutable::parse('2026-08-01 09:00:00'));
        $boundary = CarbonImmutable::parse('2026-08-20 09:00:00');

        Summary::factory()->for($loop)->create([
            'user_id' => $user->id,
            'scope' => Summary::SCOPE_INTENTION,
            'window_end' => $boundary,
        ]);

        $this->outcomeAt($loop, $boundary);

        $summary = app(WriteReflection::class)->handle($loop, 'nothing new since');

        $this->assertSame(0, $summary->events_count);
    }

    public function test_it_never_counts_another_loops_occasions(): void
    {
        $user = User::factory()->create();
        $loop = $this->loop($user, CarbonImmutable::parse('2026-08-20 09:00:00'));
        $other = $this->loop($user, CarbonImmutable::parse('2026-08-20 09:00:00'));

        $this->outcomeAt($other, CarbonImmutable::parse('2026-08-22 19:00:00'));

        $this->assertSame(0, app(WriteReflection::class)->handle($loop, 'quiet')->events_count);
    }

    public function test_a_new_reflection_appends_and_the_latest_one_wins(): void
    {
        $user = User::factory()->create();
        $loop = $this->loop($user, CarbonImmutable::parse('2026-08-20 09:00:00'));

        $first = app(WriteReflection::class)->handle($loop, 'first look');
        Carbon::setTestNow('2026-08-27 18:00:00');
        $second = app(WriteReflection::class)->handle($loop, 'second look');

        // Append-only: the earlier narrative stays as the record of what was
        // believed at the time.
        $this->assertSame(2, Summary::query()->where('intention_id', $loop->id)->count());
        $this->assertSame('first look', $first->refresh()->content);
        $this->assertSame($second->id, $loop->refresh()->latestSummary->id);
    }
}
