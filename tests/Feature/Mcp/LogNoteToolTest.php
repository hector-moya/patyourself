<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\GetLoopTool;
use App\Mcp\Tools\LogNoteTool;
use App\Models\Intention;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * Observations that belong to the loop but to no occasion. Written from the
 * conversation and — the part that matters — readable back: a field nothing can
 * read is the exact bug this whole phase exists to correct.
 */
class LogNoteToolTest extends TestCase
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

    public function test_it_stores_the_note_verbatim(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();
        $body = "  noticed it's WORSE when I skip lunch.  ";

        $response = PatYourSelfServer::actingAs($user)->tool(LogNoteTool::class, [
            'intention_id' => $loop->id,
            'note' => $body,
        ]);

        $response->assertOk();

        $this->assertSame($body, Note::firstOrFail()->body);
        $this->assertSame($body, $this->payload($response)['body']);
    }

    public function test_it_defaults_the_moment_to_now(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();

        PatYourSelfServer::actingAs($user)->tool(LogNoteTool::class, [
            'intention_id' => $loop->id,
            'note' => 'Worse when I skip lunch',
        ])->assertOk();

        $this->assertSame('2026-08-26 21:00:00', Note::firstOrFail()->noted_at->utc()->toDateTimeString());
    }

    public function test_it_accepts_a_supplied_moment(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();

        PatYourSelfServer::actingAs($user)->tool(LogNoteTool::class, [
            'intention_id' => $loop->id,
            'note' => 'Noticed this on the weekend',
            'noted_at' => '2026-08-22T10:00:00+00:00',
        ])->assertOk();

        $this->assertSame('2026-08-22 10:00:00', Note::firstOrFail()->noted_at->utc()->toDateTimeString());
    }

    public function test_it_rejects_an_empty_note(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();

        PatYourSelfServer::actingAs($user)->tool(LogNoteTool::class, [
            'intention_id' => $loop->id,
            'note' => '   ',
        ])->assertHasErrors();

        $this->assertSame(0, Note::count());
    }

    public function test_it_rejects_another_users_loop(): void
    {
        $loop = Intention::factory()->for(User::factory()->create(['timezone' => 'UTC']))->create();

        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(LogNoteTool::class, ['intention_id' => $loop->id, 'note' => 'Hijacked'])
            ->assertHasErrors(['Not found.']);

        $this->assertSame(0, Note::count());
    }

    /**
     * The whole point. log-action-outcome wrote reasons nothing could read;
     * a note that cannot be read back would repeat that mistake exactly.
     */
    public function test_notes_come_back_from_get_loop_newest_first(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();

        PatYourSelfServer::actingAs($user)->tool(LogNoteTool::class, [
            'intention_id' => $loop->id,
            'note' => 'Older observation',
            'noted_at' => '2026-08-20T10:00:00+00:00',
        ])->assertOk();

        PatYourSelfServer::actingAs($user)->tool(LogNoteTool::class, [
            'intention_id' => $loop->id,
            'note' => 'Newer observation',
            'noted_at' => '2026-08-25T10:00:00+00:00',
        ])->assertOk();

        $notes = $this->payload(
            PatYourSelfServer::actingAs($user)->tool(GetLoopTool::class, ['intention_id' => $loop->id]),
        )['notes'];

        $this->assertSame(['Newer observation', 'Older observation'], array_column($notes, 'body'));
        $this->assertSame(['id', 'body', 'noted_at'], array_keys($notes[0]));
    }
}
