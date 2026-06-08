<?php

namespace Tests\Unit\Coach;

use App\Services\Coach\Chat\ChatCoach;
use App\Services\Coach\Chat\ChatException;
use App\Services\Coach\Data\Role;
use App\Services\Coach\FakeCoachService;
use Tests\TestCase;

class ChatCoachTest extends TestCase
{
    /** @return array<string, mixed> */
    private function intentionPayload(): array
    {
        return [
            'title' => 'Read before bed',
            'description' => 'Swap scrolling for a few pages.',
            'type' => 'build',
            'cue' => 'Phone on charger at 10pm',
            'craving' => 'Wind down',
            'response' => 'Read a chapter',
            'reward' => 'Calmer sleep',
            'strategy' => [
                'intervention_point' => 'cue',
                'approach' => 'Leave the book on the pillow.',
                'rationale' => 'Make the cue obvious.',
            ],
        ];
    }

    public function test_returns_a_plain_reply_with_no_card(): void
    {
        $coach = (new FakeCoachService)->pushJson(['reply' => 'How can I help with your habits?']);

        $result = (new ChatCoach($coach))->respond('hello');

        $this->assertSame('How can I help with your habits?', $result->message);
        $this->assertNull($result->intention);

        $request = $coach->requests[0];
        $this->assertTrue($request->json);
        $system = mb_strtolower((string) $request->resolveSystem());
        $this->assertStringContainsString('reply', $system);
        // Shares the coaching charter.
        $this->assertStringContainsString('cue', $system);
    }

    public function test_authors_an_intention_card_when_the_user_describes_a_habit(): void
    {
        $coach = (new FakeCoachService)->pushJson([
            'reply' => "Let's build that.",
            'intention' => $this->intentionPayload(),
        ]);

        $result = (new ChatCoach($coach))->respond('I want to read more before bed');

        $this->assertSame("Let's build that.", $result->message);
        $this->assertNotNull($result->intention);
        $this->assertSame('Read before bed', $result->intention->title);
        $this->assertNotNull($result->intention->strategy);
    }

    public function test_threads_conversation_history_then_the_new_message(): void
    {
        $coach = (new FakeCoachService)->pushJson(['reply' => 'ok']);

        (new ChatCoach($coach))->respond('and the mornings?', [
            ['role' => 'user', 'content' => 'I want more energy'],
            ['role' => 'assistant', 'content' => 'Tell me about your mornings.'],
        ]);

        $messages = $coach->requests[0]->messagePayload();
        $this->assertCount(3, $messages);
        $this->assertSame('I want more energy', $messages[0]['content']);
        $this->assertSame(Role::Assistant->value, $messages[1]['role']);
        $this->assertSame('and the mornings?', $messages[2]['content']);
    }

    public function test_invalid_intention_card_is_dropped_but_reply_survives(): void
    {
        $coach = (new FakeCoachService)->pushJson([
            'reply' => 'Got it.',
            'intention' => ['title' => 'incomplete'],
        ]);

        $result = (new ChatCoach($coach))->respond('something');

        $this->assertSame('Got it.', $result->message);
        $this->assertNull($result->intention, 'a malformed card is dropped, not fatal');
    }

    public function test_missing_reply_is_an_error(): void
    {
        $coach = (new FakeCoachService)->pushJson(['intention' => $this->intentionPayload()]);

        $this->expectException(ChatException::class);
        (new ChatCoach($coach))->respond('hi');
    }
}
