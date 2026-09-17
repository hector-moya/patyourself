<?php

namespace Tests\Feature\Companion;

use App\Actions\MeetNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The encounter, which is the mechanic that lets a skill list exist without
 * becoming a checklist.
 *
 * Clicking a node before you have its skill does not fail and does not show a
 * lock. Blob walks over, turns it over and puts it down again — and THAT is
 * what puts the skill in the list. So the list only ever contains skills whose
 * subject you have already met, it grows, and it never shows its own length.
 * (F1 §2)
 */
class MeetNodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_meeting_a_node_records_the_encounter_at_zero(): void
    {
        $user = User::factory()->create();

        $node = app(MeetNode::class)->handle($user, 'reeds');

        $this->assertSame('reeds', $node->node);
        $this->assertSame(0, $node->available);
        $this->assertDatabaseCount('companion_nodes', 1);
    }

    /** The row is the encounter, so a companion appears for it if none existed. */
    public function test_meeting_a_node_creates_the_companion_if_there_is_none(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->companion);

        app(MeetNode::class)->handle($user, 'reeds');

        $this->assertDatabaseCount('companions', 1);
    }

    /** Meeting it again is the same encounter, not a second one. */
    public function test_meeting_a_node_twice_changes_nothing(): void
    {
        $user = User::factory()->create();

        $first = app(MeetNode::class)->handle($user, 'reeds');
        $second = app(MeetNode::class)->handle($user, 'reeds');

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('companion_nodes', 1);
    }

    /**
     * Meeting never harvests, even with the skill and a full clearing. The two
     * are separate acts: one is Blob noticing a thing, the other is Blob
     * picking it up.
     */
    public function test_meeting_a_node_never_takes_anything_from_it(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);
        $companion->nodes()->create(['node' => 'reeds', 'available' => 6]);

        $node = app(MeetNode::class)->handle($user, 'reeds');

        $this->assertSame(6, $node->available);
        $this->assertDatabaseCount('companion_items', 0);
    }

    public function test_a_node_nobody_authored_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(MeetNode::class)->handle(User::factory()->create(), 'the-moon');
    }

    /** One person's encounter is not another's. */
    public function test_meeting_a_node_belongs_to_the_one_who_met_it(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();

        app(MeetNode::class)->handle($user, 'reeds');

        $this->assertDatabaseCount('companion_nodes', 1);
        $this->assertSame(0, $stranger->companion()->count());
    }
}
