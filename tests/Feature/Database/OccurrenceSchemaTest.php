<?php

namespace Tests\Feature\Database;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Occurrence;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The invariants the occurrence schema itself has to hold, independently of
 * any service: one row per occasion, at most one outcome on it, and an anchor
 * on the action that says where its series began.
 */
class OccurrenceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_occurrence_belongs_to_an_action_and_starts_unlogged(): void
    {
        $occurrence = Occurrence::factory()->create(['scheduled_for' => now()->subDay()]);

        $this->assertInstanceOf(Action::class, $occurrence->action);
        $this->assertFalse($occurrence->isLogged());
        $this->assertNull($occurrence->log);
    }

    public function test_the_same_slot_cannot_be_materialised_twice(): void
    {
        $occurrence = Occurrence::factory()->create();

        $this->expectException(QueryException::class);

        Occurrence::factory()->create([
            'action_id' => $occurrence->action_id,
            'scheduled_for' => $occurrence->scheduled_for,
        ]);
    }

    public function test_an_occurrence_carries_at_most_one_outcome(): void
    {
        $occurrence = Occurrence::factory()->create();
        ActionLog::factory()->create([
            'action_id' => $occurrence->action_id,
            'occurrence_id' => $occurrence->id,
        ]);

        $this->expectException(QueryException::class);

        ActionLog::factory()->create([
            'action_id' => $occurrence->action_id,
            'occurrence_id' => $occurrence->id,
        ]);
    }

    public function test_the_unlogged_scope_finds_only_occasions_still_awaiting_an_outcome(): void
    {
        $logged = Occurrence::factory()->create();
        ActionLog::factory()->create([
            'action_id' => $logged->action_id,
            'occurrence_id' => $logged->id,
        ]);

        $unlogged = Occurrence::factory()->create();

        $this->assertSame([$unlogged->id], Occurrence::query()->unlogged()->pluck('id')->all());
    }

    public function test_a_log_stores_context_and_its_small_structured_field_set(): void
    {
        $log = ActionLog::factory()->create([
            'context' => 'Ate standing up while cooking, plate refilled twice',
            'context_fields' => ['place' => 'kitchen', 'with_others' => false, 'preceded_by' => 'skipped lunch'],
        ]);

        $fresh = $log->fresh();

        $this->assertSame('Ate standing up while cooking, plate refilled twice', $fresh->context);
        $this->assertSame('kitchen', $fresh->context_fields['place']);
        $this->assertFalse($fresh->context_fields['with_others']);
        $this->assertSame('skipped lunch', $fresh->context_fields['preceded_by']);
    }

    public function test_an_action_records_where_its_series_began(): void
    {
        $anchor = now()->subWeek()->startOfSecond();
        $action = Action::factory()->create(['series_started_at' => $anchor]);

        $this->assertTrue($action->fresh()->series_started_at->equalTo($anchor));
    }
}
