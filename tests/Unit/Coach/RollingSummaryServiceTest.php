<?php

namespace Tests\Unit\Coach;

use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Summary;
use App\Services\Coach\FakeCoachService;
use App\Services\Coach\Summary\RollingSummaryService;
use App\Services\Coach\Summary\SummaryException;
use Illuminate\Support\Collection;
use Tests\TestCase;

class RollingSummaryServiceTest extends TestCase
{
    private function intention(): Intention
    {
        return new Intention([
            'title' => 'Read before bed',
            'type' => Intention::TYPE_BUILD,
            'cue' => 'Phone on charger at 10pm',
            'craving' => 'Wind down',
            'response' => 'Read a chapter',
            'reward' => 'Calmer sleep',
        ]);
    }

    /** @return Collection<int, ActionLog> */
    private function events(): Collection
    {
        return collect([
            new ActionLog(['outcome' => ActionLog::OUTCOME_FAILED, 'reason' => 'Got home too late and was exhausted', 'logged_at' => '2026-06-01 22:00:00']),
            new ActionLog(['outcome' => ActionLog::OUTCOME_COMPLETED, 'reason' => null, 'logged_at' => '2026-06-02 22:00:00']),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'content' => 'Reads most nights; misses when home late after work.',
            'patterns' => ['Fails on late workdays', 'Succeeds on calm evenings'],
        ], $overrides);
    }

    public function test_folds_events_into_a_summary_with_patterns(): void
    {
        $coach = (new FakeCoachService)->pushJson($this->payload());

        $summary = (new RollingSummaryService($coach))->summarize($this->intention(), $this->events(), null);

        $this->assertStringContainsString('home late', $summary->content);
        $this->assertSame(['Fails on late workdays', 'Succeeds on calm evenings'], $summary->patterns);
        $this->assertSame('fake', $summary->model);
    }

    public function test_request_is_json_and_carries_loop_events_and_pattern_intent(): void
    {
        $coach = (new FakeCoachService)->pushJson($this->payload());

        (new RollingSummaryService($coach))->summarize($this->intention(), $this->events(), null);

        $request = $coach->requests[0];
        $this->assertTrue($request->json);

        $system = mb_strtolower((string) $request->resolveSystem());
        $this->assertStringContainsString('pattern', $system);
        $this->assertStringContainsString('summary', $system);

        $userTurn = $request->messagePayload()[0]['content'];
        $this->assertStringContainsString('Read before bed', $userTurn);
        // The user-stated failure reason must reach the model.
        $this->assertStringContainsString('Got home too late', $userTurn);
    }

    public function test_prior_summary_is_folded_in_for_a_rolling_update(): void
    {
        $coach = (new FakeCoachService)->pushJson($this->payload());
        $previous = new Summary(['content' => 'PRIOR_ROLLING_TEXT', 'scope' => Summary::SCOPE_INTENTION]);

        (new RollingSummaryService($coach))->summarize($this->intention(), $this->events(), $previous);

        $userTurn = $coach->requests[0]->messagePayload()[0]['content'];
        $this->assertStringContainsString('PRIOR_ROLLING_TEXT', $userTurn);
    }

    public function test_rejects_summary_without_content(): void
    {
        $payload = $this->payload();
        unset($payload['content']);
        $coach = (new FakeCoachService)->pushJson($payload);

        $this->expectException(SummaryException::class);
        (new RollingSummaryService($coach))->summarize($this->intention(), $this->events(), null);
    }
}
