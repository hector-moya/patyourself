<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\IntentionAuthor;
use App\Ai\Tools\CreateLoop;
use App\Ai\TurnCollector;
use App\Models\Intention;
use App\Models\User;
use App\Services\Coach\Exceptions\CoachException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;

class CreateLoopTest extends TestCase
{
    use RefreshDatabase;

    private function authoredPayload(): array
    {
        return [
            'title' => 'Read before bed',
            'description' => 'Swap scrolling for pages.',
            'type' => 'build',
            'cue' => 'Phone on charger at 10pm',
            'craving' => 'Wind down',
            'response' => 'Read a chapter',
            'reward' => 'Calmer sleep',
            'confidence' => 0.8,
            'strategy' => [
                'intervention_point' => 'cue',
                'approach' => 'Leave the book on the pillow.',
                'rationale' => 'Make the cue obvious.',
            ],
        ];
    }

    public function test_authors_persists_and_registers_the_loop(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        IntentionAuthor::fake([$this->authoredPayload()]);

        $tool = $this->app->make(CreateLoop::class);
        $result = (string) $tool->handle(new ToolRequest(['goal' => 'I want to read more before bed']));

        $intention = Intention::sole();
        $this->assertSame('Read before bed', $intention->title);
        $this->assertSame($user->id, $intention->user_id);
        $this->assertSame(1, $intention->strategies()->count());
        $this->assertSame([$intention->id], $this->app->make(TurnCollector::class)->intentionIds());
        $this->assertStringContainsString('Read before bed', $result);
    }

    public function test_a_malformed_authoring_payload_creates_nothing(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        IntentionAuthor::fake([[]]);

        $tool = $this->app->make(CreateLoop::class);

        $this->expectException(CoachException::class);

        try {
            $tool->handle(new ToolRequest(['goal' => 'anything']));
        } finally {
            $this->assertSame(0, Intention::count());
            $this->assertSame([], $this->app->make(TurnCollector::class)->intentionIds());
        }
    }

    public function test_whitespace_only_fields_create_nothing(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $payload = $this->authoredPayload();
        $payload['title'] = '   ';
        IntentionAuthor::fake([$payload]);

        $tool = $this->app->make(CreateLoop::class);

        $this->expectException(CoachException::class);

        try {
            $tool->handle(new ToolRequest(['goal' => 'anything']));
        } finally {
            $this->assertSame(0, Intention::count());
            $this->assertSame([], $this->app->make(TurnCollector::class)->intentionIds());
        }
    }

    public function test_an_invalid_nested_strategy_creates_nothing(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $payload = $this->authoredPayload();
        $payload['strategy']['intervention_point'] = 'nonsense';
        IntentionAuthor::fake([$payload]);

        $tool = $this->app->make(CreateLoop::class);

        $this->expectException(CoachException::class);

        try {
            $tool->handle(new ToolRequest(['goal' => 'anything']));
        } finally {
            $this->assertSame(0, Intention::count());
            $this->assertSame([], $this->app->make(TurnCollector::class)->intentionIds());
        }
    }
}
