<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\LogOutcomeTool;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * The one write a check-in makes. Every case the previous log-action-outcome
 * tool guarded is carried over here, plus the ones the occurrence model makes
 * possible: logging a past occasion, and doing it without disturbing what is
 * due next.
 */
class LogOutcomeToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-26 21:00:00');
    }

    /**
     * @return array<mixed>
     */
    private function payload(TestResponse $response): array
    {
        $content = new \ReflectionMethod($response, 'content');

        /** @var array<int, string> $text */
        $text = $content->invoke($response);

        return json_decode($text[0], true, flags: JSON_THROW_ON_ERROR);
    }

    private function oneOffAction(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user))
            ->create([
                'status' => Action::STATUS_ACTIVE,
                'recurrence' => null,
                'series_started_at' => null,
            ]);
    }

    private function recurringAction(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user))
            ->create([
                'status' => Action::STATUS_ACTIVE,
                'recurrence' => 'daily',
                'series_started_at' => now()->subDays(5)->setTime(19, 0),
            ]);
    }

    private function occurrenceFor(Action $action, ?string $at = null): Occurrence
    {
        return Occurrence::factory()->create([
            'action_id' => $action->id,
            'scheduled_for' => $at === null ? now()->subDays(3)->setTime(19, 0) : $at,
        ]);
    }

    public function test_logs_a_completion(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->oneOffAction($user);
        $occurrence = $this->occurrenceFor($action);

        $response = PatYourSelfServer::actingAs($user)
            ->tool(LogOutcomeTool::class, [
                'occurrence_id' => $occurrence->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
            ]);

        $response->assertOk();

        $payload = $this->payload($response);

        $this->assertSame([
            'log_id', 'occurrence_id', 'occurred_at', 'outcome', 'reason',
            'context', 'context_fields', 'loop_id', 'loop_title', 'action_title',
        ], array_keys($payload));
        $this->assertSame(ActionLog::OUTCOME_COMPLETED, $payload['outcome']);
        $this->assertNull($payload['reason']);
        $this->assertSame($occurrence->id, $payload['occurrence_id']);
        $this->assertIsInt($payload['log_id']);

        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'occurrence_id' => $occurrence->id,
            'user_id' => $user->id,
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ]);
    }

    public function test_logs_a_skip(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->oneOffAction($user);
        $occurrence = $this->occurrenceFor($action);

        PatYourSelfServer::actingAs($user)
            ->tool(LogOutcomeTool::class, [
                'occurrence_id' => $occurrence->id,
                'outcome' => ActionLog::OUTCOME_SKIPPED,
            ])
            ->assertOk();

        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'outcome' => ActionLog::OUTCOME_SKIPPED,
        ]);
    }

    public function test_rejects_a_wholly_unknown_occurrence_id(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(LogOutcomeTool::class, [
                'occurrence_id' => 999999,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
            ])
            ->assertHasErrors(['Not found.']);
    }

    public function test_a_failure_requires_a_reason(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $occurrence = $this->occurrenceFor($this->oneOffAction($user));

        PatYourSelfServer::actingAs($user)
            ->tool(LogOutcomeTool::class, [
                'occurrence_id' => $occurrence->id,
                'outcome' => ActionLog::OUTCOME_FAILED,
            ])
            ->assertHasErrors();

        $this->assertSame(0, ActionLog::count());
    }

    public function test_logs_a_failure_with_its_reason(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $occurrence = $this->occurrenceFor($this->oneOffAction($user));

        PatYourSelfServer::actingAs($user)
            ->tool(LogOutcomeTool::class, [
                'occurrence_id' => $occurrence->id,
                'outcome' => ActionLog::OUTCOME_FAILED,
                'reason' => 'Friends came over unexpectedly',
            ])
            ->assertOk();

        $this->assertDatabaseHas('action_logs', [
            'occurrence_id' => $occurrence->id,
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Friends came over unexpectedly',
        ]);
    }

    public function test_it_stores_the_reason_verbatim(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $occurrence = $this->occurrenceFor($this->recurringAction($user));
        $reason = "  didn't Think about it AT ALL.  ";

        PatYourSelfServer::actingAs($user)
            ->tool(LogOutcomeTool::class, [
                'occurrence_id' => $occurrence->id,
                'outcome' => ActionLog::OUTCOME_FAILED,
                'reason' => $reason,
            ])
            ->assertOk();

        $this->assertSame($reason, ActionLog::firstOrFail()->reason);
    }

    public function test_completing_a_recurring_action_on_its_live_slot_leaves_the_action_row_alone(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);
        $seriesStartedAt = $action->series_started_at;
        $occurrence = $this->occurrenceFor($action, now()->setTime(19, 0)->toDateTimeString());

        PatYourSelfServer::actingAs($user)
            ->tool(LogOutcomeTool::class, [
                'occurrence_id' => $occurrence->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
            ])
            ->assertOk();

        $fresh = $action->fresh();
        $this->assertSame(Action::STATUS_ACTIVE, $fresh->status);
        $this->assertTrue($fresh->series_started_at->equalTo($seriesStartedAt));
    }

    public function test_it_logs_a_past_occasion_without_moving_the_next_due_pointer(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);
        $seriesStartedAt = $action->series_started_at;
        $occurrence = $this->occurrenceFor($action);

        $response = PatYourSelfServer::actingAs($user)
            ->tool(LogOutcomeTool::class, [
                'occurrence_id' => $occurrence->id,
                'outcome' => ActionLog::OUTCOME_FAILED,
                'reason' => 'Second plate before I noticed',
            ]);

        $response->assertOk();

        $this->assertSame($occurrence->id, $this->payload($response)['occurrence_id']);
        $this->assertTrue($action->fresh()->series_started_at->equalTo($seriesStartedAt));
    }

    public function test_it_refuses_to_log_an_occasion_twice(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $occurrence = $this->occurrenceFor($this->recurringAction($user));

        $args = [
            'occurrence_id' => $occurrence->id,
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ];

        PatYourSelfServer::actingAs($user)->tool(LogOutcomeTool::class, $args)->assertOk();
        PatYourSelfServer::actingAs($user)->tool(LogOutcomeTool::class, $args)
            ->assertHasErrors(['That occasion already has an outcome.']);

        $this->assertSame(1, ActionLog::count());
    }

    public function test_it_logs_a_cue_anchored_action_ad_hoc_against_a_supplied_datetime(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->oneOffAction($user);

        PatYourSelfServer::actingAs($user)
            ->tool(LogOutcomeTool::class, [
                'action_id' => $action->id,
                'occurred_at' => '2026-08-24T19:30:00+00:00',
                'outcome' => ActionLog::OUTCOME_SKIPPED,
            ])
            ->assertOk();

        $this->assertSame(
            '2026-08-24 19:30:00',
            Occurrence::firstOrFail()->scheduled_for->utc()->toDateTimeString(),
        );
    }

    public function test_it_stores_context_and_its_structured_fields(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $occurrence = $this->occurrenceFor($this->recurringAction($user));

        $response = PatYourSelfServer::actingAs($user)
            ->tool(LogOutcomeTool::class, [
                'occurrence_id' => $occurrence->id,
                'outcome' => ActionLog::OUTCOME_FAILED,
                'reason' => 'Kept going past full',
                'context' => 'Standing at the bench, plate refilled straight away',
                'context_fields' => ['place' => 'kitchen', 'with_others' => false, 'preceded_by' => 'skipped lunch'],
            ]);

        $response->assertOk();

        $payload = $this->payload($response);
        $this->assertSame('Standing at the bench, plate refilled straight away', $payload['context']);
        $this->assertSame('kitchen', $payload['context_fields']['place']);
        $this->assertFalse($payload['context_fields']['with_others']);
    }

    public function test_it_rejects_a_call_that_names_neither_an_occurrence_nor_an_action(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(LogOutcomeTool::class, ['outcome' => ActionLog::OUTCOME_COMPLETED])
            ->assertHasErrors();
    }

    public function test_it_rejects_a_call_that_names_both(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);
        $occurrence = $this->occurrenceFor($action);

        PatYourSelfServer::actingAs($user)
            ->tool(LogOutcomeTool::class, [
                'occurrence_id' => $occurrence->id,
                'action_id' => $action->id,
                'occurred_at' => now()->toIso8601String(),
                'outcome' => ActionLog::OUTCOME_COMPLETED,
            ])
            ->assertHasErrors();

        $this->assertSame(0, ActionLog::count());
    }

    /**
     * The field set is closed, and `calories` is doubly unwelcome: no quantity
     * belongs anywhere in an eating loop's data model.
     */
    public function test_it_rejects_an_unknown_context_field(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $occurrence = $this->occurrenceFor($this->recurringAction($user));

        PatYourSelfServer::actingAs($user)
            ->tool(LogOutcomeTool::class, [
                'occurrence_id' => $occurrence->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
                'context_fields' => ['calories' => 900],
            ])
            ->assertHasErrors();

        $this->assertSame(0, ActionLog::count());
    }

    public function test_cannot_log_another_users_occasion(): void
    {
        $occurrence = $this->occurrenceFor(
            $this->recurringAction(User::factory()->create(['timezone' => 'UTC'])),
        );

        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(LogOutcomeTool::class, [
                'occurrence_id' => $occurrence->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
            ])
            ->assertHasErrors(['Not found.']);

        $this->assertSame(0, ActionLog::count());
    }

    public function test_cannot_ad_hoc_log_another_users_action(): void
    {
        $action = $this->oneOffAction(User::factory()->create(['timezone' => 'UTC']));

        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(LogOutcomeTool::class, [
                'action_id' => $action->id,
                'occurred_at' => now()->toIso8601String(),
                'outcome' => ActionLog::OUTCOME_COMPLETED,
            ])
            ->assertHasErrors(['Not found.']);

        $this->assertSame(0, ActionLog::count());
    }

    public function test_a_failure_reason_at_the_2000_character_limit_is_accepted(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $occurrence = $this->occurrenceFor($this->oneOffAction($user));
        $reason = str_repeat('a', 2000);

        PatYourSelfServer::actingAs($user)
            ->tool(LogOutcomeTool::class, [
                'occurrence_id' => $occurrence->id,
                'outcome' => ActionLog::OUTCOME_FAILED,
                'reason' => $reason,
            ])
            ->assertOk();

        $this->assertDatabaseHas('action_logs', [
            'occurrence_id' => $occurrence->id,
            'reason' => $reason,
        ]);
    }

    public function test_a_failure_reason_over_2000_characters_is_rejected(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $occurrence = $this->occurrenceFor($this->oneOffAction($user));

        PatYourSelfServer::actingAs($user)
            ->tool(LogOutcomeTool::class, [
                'occurrence_id' => $occurrence->id,
                'outcome' => ActionLog::OUTCOME_FAILED,
                'reason' => str_repeat('a', 2001),
            ])
            ->assertHasErrors();

        $this->assertSame(0, ActionLog::count());
    }

    public function test_rejects_an_unknown_outcome(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $occurrence = $this->occurrenceFor($this->oneOffAction($user));

        PatYourSelfServer::actingAs($user)
            ->tool(LogOutcomeTool::class, [
                'occurrence_id' => $occurrence->id,
                'outcome' => 'exploded',
            ])
            ->assertHasErrors();
    }
}
