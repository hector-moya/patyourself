<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\CreateLoopTool;
use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class CreateLoopToolTest extends TestCase
{
    use RefreshDatabase;

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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function arguments(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Read before bed',
            'type' => Intention::TYPE_BUILD,
            'cue' => 'Phone goes on the charger',
            'craving' => 'Wind down',
            'response' => 'Read ten pages',
            'reward' => 'Calmer sleep',
            'strategy' => [
                'intervention_point' => Strategy::POINT_CUE,
                'approach' => 'Put the book on the pillow',
                'rationale' => 'Makes the cue impossible to miss',
            ],
        ], $overrides);
    }

    public function test_creates_the_loop_paused_with_its_first_strategy(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $response = PatYourSelfServer::actingAs($user)
            ->tool(CreateLoopTool::class, $this->arguments());

        $response->assertOk();

        $payload = $this->payload($response);

        $this->assertSame(
            ['loop_id', 'title', 'status', 'next_step'],
            array_keys($payload),
        );
        $this->assertSame(Intention::STATUS_PAUSED, $payload['status']);
        $this->assertSame('Read before bed', $payload['title']);

        $intention = Intention::findOrFail($payload['loop_id']);

        $this->assertSame($user->id, $intention->user_id);
        $this->assertSame(Intention::STATUS_PAUSED, $intention->status);
        $this->assertSame('Phone goes on the charger', $intention->cue);

        $strategy = $intention->strategies()->sole();

        $this->assertSame(1, $strategy->version);
        $this->assertSame(Strategy::POINT_CUE, $strategy->intervention_point);
    }

    public function test_records_the_client_as_the_author(): void
    {
        $user = User::factory()->create();

        $response = PatYourSelfServer::actingAs($user)
            ->tool(CreateLoopTool::class, $this->arguments());

        $intention = Intention::findOrFail($this->payload($response)['loop_id']);

        $this->assertSame(CreateLoopTool::AUTHORED_BY, $intention->metadata['authored_by']);
    }

    public function test_creates_the_optional_first_action(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $response = PatYourSelfServer::actingAs($user)
            ->tool(CreateLoopTool::class, $this->arguments([
                'action' => [
                    'title' => 'Read ten pages',
                    'kind' => 'clock',
                    'time' => '21:30',
                    'recurrence' => 'daily',
                ],
            ]));

        $intention = Intention::findOrFail($this->payload($response)['loop_id']);
        $action = $intention->actions()->sole();

        $this->assertSame('Read ten pages', $action->title);
        $this->assertSame('daily', $action->recurrence);
        $this->assertSame(Action::STATUS_PENDING, $action->status);
    }

    public function test_creates_no_action_when_the_block_is_absent(): void
    {
        $user = User::factory()->create();

        $response = PatYourSelfServer::actingAs($user)
            ->tool(CreateLoopTool::class, $this->arguments());

        $intention = Intention::findOrFail($this->payload($response)['loop_id']);

        $this->assertSame(0, $intention->actions()->count());
    }

    public function test_the_loop_belongs_only_to_the_calling_user(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        PatYourSelfServer::actingAs($owner)
            ->tool(CreateLoopTool::class, $this->arguments())
            ->assertOk();

        $this->assertSame(0, $stranger->intentions()->count());
    }

    public function test_rejects_an_unknown_type(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(CreateLoopTool::class, $this->arguments(['type' => 'sideways']))
            ->assertHasErrors(['The selected type is invalid.']);
    }

    public function test_rejects_an_unknown_intervention_point(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(CreateLoopTool::class, $this->arguments([
                'strategy' => ['intervention_point' => 'vibes', 'approach' => 'x'],
            ]))
            ->assertHasErrors(['The selected strategy.intervention point is invalid.']);
    }

    public function test_rejects_a_clock_action_without_a_time(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(CreateLoopTool::class, $this->arguments([
                'action' => ['title' => 'Read', 'kind' => 'clock', 'recurrence' => 'daily'],
            ]))
            ->assertHasErrors(['The action.time field is required.']);
    }

    public function test_rejects_an_anchored_action_without_an_anchor(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(CreateLoopTool::class, $this->arguments([
                'action' => ['title' => 'Read', 'kind' => 'anchored'],
            ]))
            ->assertHasErrors(['The action.anchor field is required.']);
    }

    public function test_rejects_an_empty_action_block(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(CreateLoopTool::class, $this->arguments(['action' => []]))
            ->assertHasErrors(['The action field must have at least 1 items.']);
    }

    public function test_returns_a_tool_error_when_the_strategy_block_is_structurally_invalid(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(CreateLoopTool::class, $this->arguments([
                'strategy' => [
                    'intervention_point' => Strategy::POINT_CUE,
                    'approach' => '   ',
                ],
            ]))
            ->assertHasErrors(['The strategy.approach field is required.']);
    }

    public function test_prompts_no_agent(): void
    {
        $user = User::factory()->create();

        PatYourSelfServer::actingAs($user)
            ->tool(CreateLoopTool::class, $this->arguments())
            ->assertOk();
    }
}
