<?php

namespace Tests\Unit\Coach;

use App\Services\Coach\Authoring\IntentionAuthor;
use App\Services\Coach\Authoring\IntentionAuthoringException;
use App\Services\Coach\Data\CoachResponse;
use App\Services\Coach\Exceptions\CoachException;
use App\Services\Coach\FakeCoachService;
use Tests\TestCase;

class IntentionAuthorTest extends TestCase
{
    /**
     * A well-formed structured payload the LLM is expected to author.
     *
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Read before bed',
            'description' => 'Swap late-night scrolling for a few pages of a book.',
            'type' => 'build',
            'cue' => 'Phone goes on the charger at 10pm',
            'craving' => 'Wind down without a screen',
            'response' => 'Read one chapter of a paper book',
            'reward' => 'Calmer, falls asleep faster',
            'confidence' => 0.82,
            'tags' => ['sleep', 'evening'],
            'strategy' => [
                'intervention_point' => 'cue',
                'approach' => 'Leave the book on the pillow so it is the first thing in reach.',
                'rationale' => 'Making the cue obvious lowers the activation energy.',
            ],
        ], $overrides);
    }

    public function test_authors_structured_intention_from_valid_json(): void
    {
        $coach = (new FakeCoachService)->pushJson($this->validPayload());

        $authored = (new IntentionAuthor($coach))->author('I keep scrolling instead of sleeping');

        $this->assertSame('Read before bed', $authored->title);
        $this->assertSame('build', $authored->type);
        $this->assertSame('Phone goes on the charger at 10pm', $authored->cue);
        $this->assertSame('Wind down without a screen', $authored->craving);
        $this->assertSame('Read one chapter of a paper book', $authored->response);
        $this->assertSame('Calmer, falls asleep faster', $authored->reward);
        $this->assertSame(0.82, $authored->confidence);
        $this->assertSame(['sleep', 'evening'], $authored->tags);
        $this->assertSame('fake', $authored->model);

        $this->assertNotNull($authored->strategy);
        $this->assertSame('cue', $authored->strategy->interventionPoint);
        $this->assertNotSame('', $authored->strategy->approach);
    }

    public function test_request_asks_for_json_and_carries_the_schema_contract(): void
    {
        $coach = (new FakeCoachService)->pushJson($this->validPayload());

        (new IntentionAuthor($coach))->author('I want a morning walk habit');

        $request = $coach->requests[0];
        $this->assertTrue($request->json, 'authoring must request JSON output');

        $system = (string) $request->resolveSystem();
        // The contract must name the chain fields so the model authors them.
        foreach (['cue', 'craving', 'response', 'reward', 'intervention_point'] as $field) {
            $this->assertStringContainsString($field, $system);
        }

        $userTurn = $request->messagePayload()[0]['content'];
        $this->assertStringContainsString('morning walk', $userTurn);
    }

    public function test_tolerates_prose_and_fenced_json(): void
    {
        $fenced = "Here is the loop:\n```json\n".json_encode($this->validPayload())."\n```";
        $coach = (new FakeCoachService)->push(new CoachResponse(content: $fenced, model: 'fake'));

        $authored = (new IntentionAuthor($coach))->author('help me read more');

        $this->assertSame('Read before bed', $authored->title);
    }

    public function test_strategy_is_optional(): void
    {
        $payload = $this->validPayload();
        unset($payload['strategy']);
        $coach = (new FakeCoachService)->pushJson($payload);

        $authored = (new IntentionAuthor($coach))->author('drink more water');

        $this->assertNull($authored->strategy);
    }

    public function test_rejects_missing_required_chain_field(): void
    {
        $payload = $this->validPayload();
        unset($payload['cue']);
        $coach = (new FakeCoachService)->pushJson($payload);

        $this->expectException(IntentionAuthoringException::class);
        (new IntentionAuthor($coach))->author('anything');
    }

    public function test_rejects_invalid_type(): void
    {
        $coach = (new FakeCoachService)->pushJson($this->validPayload(['type' => 'sideways']));

        $this->expectException(IntentionAuthoringException::class);
        (new IntentionAuthor($coach))->author('anything');
    }

    public function test_rejects_invalid_intervention_point(): void
    {
        $payload = $this->validPayload();
        $payload['strategy']['intervention_point'] = 'elsewhere';
        $coach = (new FakeCoachService)->pushJson($payload);

        $this->expectException(IntentionAuthoringException::class);
        (new IntentionAuthor($coach))->author('anything');
    }

    public function test_propagates_invalid_json_as_coach_exception(): void
    {
        $coach = (new FakeCoachService)->push('this is not json at all');

        $this->expectException(CoachException::class);
        (new IntentionAuthor($coach))->author('anything');
    }
}
