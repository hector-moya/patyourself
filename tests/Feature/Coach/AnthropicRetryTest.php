<?php

namespace Tests\Feature\Coach;

use App\Services\Coach\Data\CoachRequest;
use App\Services\Coach\Data\Message;
use App\Services\Coach\Drivers\AnthropicCoachService;
use App\Services\Coach\Exceptions\CoachException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Anthropic driver retries only transient failures (connection drops, 429s,
 * 5xx) and surfaces everything else as a CoachException — never a raw client
 * error.
 */
class AnthropicRetryTest extends TestCase
{
    private function driver(): AnthropicCoachService
    {
        return new AnthropicCoachService($this->app->make(HttpFactory::class), [
            'key' => 'test-key',
            'model' => 'claude-sonnet-4-6',
            'base_url' => 'https://api.anthropic.com',
            'retries' => 2,
            'timeout' => 5,
        ]);
    }

    private function request(): CoachRequest
    {
        return new CoachRequest(messages: [Message::user('hi')]);
    }

    /** @return array<string, mixed> */
    private function okBody(): array
    {
        return [
            'content' => [['type' => 'text', 'text' => 'hello']],
            'model' => 'claude-sonnet-4-6',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
            'stop_reason' => 'end_turn',
        ];
    }

    public function test_a_transient_5xx_is_retried_then_succeeds(): void
    {
        Http::fake([
            '*/v1/messages' => Http::sequence()
                ->push(['error' => 'overloaded'], 503)
                ->push($this->okBody(), 200),
        ]);

        $response = $this->driver()->chat($this->request());

        $this->assertSame('hello', $response->content);
        Http::assertSentCount(2);
    }

    public function test_a_client_4xx_is_not_retried(): void
    {
        Http::fake([
            '*/v1/messages' => Http::response(['error' => 'bad request'], 400),
        ]);

        try {
            $this->driver()->chat($this->request());
            $this->fail('Expected a CoachException.');
        } catch (CoachException) {
            // The 4xx is surfaced immediately, with no retry.
            Http::assertSentCount(1);
        }
    }

    public function test_persistent_failures_exhaust_retries_then_throw(): void
    {
        Http::fake([
            '*/v1/messages' => Http::response(['error' => 'down'], 503),
        ]);

        $this->expectException(CoachException::class);

        try {
            $this->driver()->chat($this->request());
        } finally {
            // `retries` is the total attempt budget (Laravel retry semantics).
            Http::assertSentCount(2);
        }
    }
}
