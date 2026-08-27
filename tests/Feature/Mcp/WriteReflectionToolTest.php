<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\WriteReflectionTool;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\Summary;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * Writing the rolling narrative from a conversation. The coach supplies the
 * words and nothing else — the window and the count are the app's to state, so
 * a reflection cannot claim evidence it never read.
 */
class WriteReflectionToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-27 12:00:00');
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

    private function loopFor(User $user): Intention
    {
        $loop = Intention::factory()->for($user)->create();

        Strategy::factory()->for($loop)->create([
            'version' => 1,
            'status' => Strategy::STATUS_ACTIVE,
            'created_at' => CarbonImmutable::parse('2026-08-20 09:00:00'),
        ]);

        return $loop->refresh();
    }

    public function test_it_writes_the_narrative_and_reports_the_window(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopFor($user);

        $response = PatYourSelfServer::actingAs($user)->tool(WriteReflectionTool::class, [
            'intention_id' => $loop->id,
            'content' => 'The pause holds at dinner and falls apart at lunch.',
        ]);

        $response->assertOk();
        $payload = $this->payload($response);

        $this->assertSame($loop->id, $payload['loop_id']);
        $this->assertSame('The pause holds at dinner and falls apart at lunch.', $payload['content']);
        $this->assertSame(0, $payload['events_count']);
        $this->assertStringStartsWith('2026-08-20', $payload['window_start']);
        $this->assertStringStartsWith('2026-08-27', $payload['window_end']);
        $this->assertDatabaseHas('summaries', [
            'intention_id' => $loop->id,
            'scope' => Summary::SCOPE_INTENTION,
        ]);
    }

    public function test_it_stores_the_narrative_verbatim(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopFor($user);

        // Padded and mixed-case on purpose, so a trimming implementation fails.
        $content = '  the pause HOLDS at dinner, and falls apart at lunch.  ';

        $response = PatYourSelfServer::actingAs($user)->tool(WriteReflectionTool::class, [
            'intention_id' => $loop->id,
            'content' => $content,
        ]);

        $response->assertOk();
        $this->assertSame($content, $this->payload($response)['content']);
        $this->assertDatabaseHas('summaries', ['intention_id' => $loop->id, 'content' => $content]);
    }

    public function test_a_new_reflection_supersedes_the_last_one_on_the_loop_record(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopFor($user);

        PatYourSelfServer::actingAs($user)->tool(WriteReflectionTool::class, [
            'intention_id' => $loop->id,
            'content' => 'first look',
        ]);

        $this->travelTo('2026-08-27 18:00:00');

        PatYourSelfServer::actingAs($user)->tool(WriteReflectionTool::class, [
            'intention_id' => $loop->id,
            'content' => 'second look',
        ]);

        // Append-only: both rows survive, the newer one is what reads back.
        $this->assertSame(2, Summary::query()->where('intention_id', $loop->id)->count());
        $this->assertSame('second look', $loop->refresh()->latestSummary->content);
    }

    public function test_it_rejects_blank_content(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopFor($user);

        $response = PatYourSelfServer::actingAs($user)->tool(WriteReflectionTool::class, [
            'intention_id' => $loop->id,
            'content' => '   ',
        ]);

        $response->assertHasErrors();
        $this->assertDatabaseCount('summaries', 0);
    }

    public function test_it_never_writes_to_another_users_loop(): void
    {
        $loop = $this->loopFor(User::factory()->create());

        $response = PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(WriteReflectionTool::class, [
                'intention_id' => $loop->id,
                'content' => 'not mine to write',
            ]);

        $response->assertHasErrors();
        $this->assertDatabaseCount('summaries', 0);
    }
}
