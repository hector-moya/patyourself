<?php

namespace Tests\Unit\Export;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Note;
use App\Models\Strategy;
use App\Models\Summary;
use App\Models\User;
use App\Services\Export\RecordExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_carries_a_failure_reason_out_byte_for_byte(): void
    {
        // Leading and trailing whitespace on purpose: the app stores what the
        // user wrote, and the export is not allowed to tidy it.
        $reason = '  the cue never fired, and I did not notice until bedtime  ';

        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop, 'intention')->create(['status' => Action::STATUS_ACTIVE]);
        $occurrence = $action->occurrences()->create(['scheduled_for' => now()->subDay()]);
        $occurrence->log()->create([
            'user_id' => $user->id,
            'action_id' => $action->id,
            'outcome' => 'failed',
            'reason' => $reason,
            'logged_at' => now()->subDay(),
        ]);

        $record = app(RecordExport::class)->forUser($user);

        $this->assertSame(
            $reason,
            $record['loops'][0]['actions'][0]['occurrences'][0]['outcome']['reason'],
        );
    }

    public function test_it_carries_the_chain_and_every_strategy_version(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create([
            'cue' => 'the kettle clicks off',
            'craving' => 'something to do with my hands',
            'response' => 'ten press-ups',
            'reward' => 'the coffee tastes earned',
        ]);
        Strategy::factory()->for($loop, 'intention')->create([
            'version' => 1,
            'verdict' => 'abandoned',
            'verdict_note' => 'the kettle is not a reliable cue on weekends',
        ]);
        Strategy::factory()->for($loop, 'intention')->create(['version' => 2, 'verdict' => null]);

        $record = app(RecordExport::class)->forUser($user);
        $loopRecord = $record['loops'][0];

        $this->assertSame('the kettle clicks off', $loopRecord['chain']['cue']);
        $this->assertSame('the coffee tastes earned', $loopRecord['chain']['reward']);
        $this->assertCount(2, $loopRecord['strategies']);
        $this->assertSame(1, $loopRecord['strategies'][0]['version']);
        $this->assertSame('abandoned', $loopRecord['strategies'][0]['verdict']);
        $this->assertSame(
            'the kettle is not a reliable cue on weekends',
            $loopRecord['strategies'][0]['verdict_note'],
        );
    }

    public function test_it_carries_notes_and_reflections(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        Note::factory()->for($loop, 'intention')->create([
            'body' => 'easier on days I sleep badly, which I did not expect',
            'noted_at' => now()->subDays(2),
        ]);
        $loop->summaries()->create([
            'user_id' => $user->id,
            'scope' => Summary::SCOPE_INTENTION,
            'content' => 'three weeks in, the cue is the problem and not the response',
            'events_count' => 12,
        ]);

        $record = app(RecordExport::class)->forUser($user);

        $this->assertSame(
            'easier on days I sleep badly, which I did not expect',
            $record['loops'][0]['notes'][0]['body'],
        );
        $this->assertSame(
            'three weeks in, the cue is the problem and not the response',
            $record['loops'][0]['reflections'][0]['content'],
        );
    }

    public function test_it_carries_notes_in_chronological_order(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        // Created in reverse order on purpose: the export orders by
        // `noted_at`, never by insertion order.
        Note::factory()->for($loop, 'intention')->create([
            'body' => 'noted more recently',
            'noted_at' => now()->subDay(),
        ]);
        Note::factory()->for($loop, 'intention')->create([
            'body' => 'noted first',
            'noted_at' => now()->subWeek(),
        ]);

        $record = app(RecordExport::class)->forUser($user);
        $notes = $record['loops'][0]['notes'];

        $this->assertSame('noted first', $notes[0]['body']);
        $this->assertSame('noted more recently', $notes[1]['body']);
    }

    public function test_it_carries_reflections_in_chronological_order(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        // Factory (not the relation) so `created_at` can be forced apart —
        // Summary does not fillable-expose it for mass assignment.
        Summary::factory()->for($loop, 'intention')->create([
            'user_id' => $user->id,
            'content' => 'summary written second',
            'created_at' => now(),
        ]);
        Summary::factory()->for($loop, 'intention')->create([
            'user_id' => $user->id,
            'content' => 'summary written first',
            'created_at' => now()->subWeek(),
        ]);

        $record = app(RecordExport::class)->forUser($user);
        $reflections = $record['loops'][0]['reflections'];

        $this->assertSame('summary written first', $reflections[0]['content']);
        $this->assertSame('summary written second', $reflections[1]['content']);
    }

    public function test_it_carries_the_outcome_context_out_byte_for_byte(): void
    {
        // Leading and trailing whitespace on purpose, same as the reason
        // field: `context` is the primary, free-text record of what
        // happened, and it is not allowed to be tidied either.
        $context = '  pressed snooze twice before giving up entirely  ';

        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop, 'intention')->create(['status' => Action::STATUS_ACTIVE]);
        $occurrence = $action->occurrences()->create(['scheduled_for' => now()->subDay()]);
        $occurrence->log()->create([
            'user_id' => $user->id,
            'action_id' => $action->id,
            'outcome' => 'failed',
            'reason' => 'overslept',
            'context' => $context,
            'context_fields' => ['mood' => 'tired'],
            'logged_at' => now()->subDay(),
        ]);

        $record = app(RecordExport::class)->forUser($user);
        $outcome = $record['loops'][0]['actions'][0]['occurrences'][0]['outcome'];

        $this->assertSame($context, $outcome['context']);
        $this->assertSame(['mood' => 'tired'], $outcome['context_fields']);
    }

    public function test_another_users_record_never_appears(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        Intention::factory()->for($mine)->create(['title' => 'mine']);
        Intention::factory()->for($theirs)->create(['title' => 'theirs']);

        $record = app(RecordExport::class)->forUser($mine);

        $this->assertCount(1, $record['loops']);
        $this->assertSame('mine', $record['loops'][0]['title']);
    }

    public function test_an_empty_account_produces_a_valid_document(): void
    {
        $user = User::factory()->create();

        $record = app(RecordExport::class)->forUser($user);

        $this->assertSame([], $record['loops']);
        $this->assertSame($user->email, $record['user']['email']);
        $this->assertNotEmpty($record['exported_at']);
    }
}
