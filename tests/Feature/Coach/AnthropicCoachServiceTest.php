<?php

namespace Tests\Feature\Coach;

use App\Services\Coach\Data\CoachRequest;
use App\Services\Coach\Data\Message;
use App\Services\Coach\Drivers\AnthropicCoachService;
use App\Services\Coach\Exceptions\CoachException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicCoachServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $config = [
        'key' => 'sk-test',
        'model' => 'claude-sonnet-4-6',
        'base_url' => 'https://api.anthropic.com',
        'max_tokens' => 256,
        'temperature' => 0.5,
        'timeout' => 5,
        'retries' => 0,
    ];

    private function driver(): AnthropicCoachService
    {
        return new AnthropicCoachService(app(HttpFactory::class), $this->config);
    }

    /** @return array<string, mixed> */
    private function okBody(string $text = 'Hello there.'): array
    {
        return [
            'model' => 'claude-sonnet-4-6',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => $text]],
            'usage' => ['input_tokens' => 11, 'output_tokens' => 7],
        ];
    }

    public function test_it_normalizes_a_successful_response()
    {
        Http::fake(['*' => Http::response($this->okBody('Hi!'), 200)]);

        $response = $this->driver()->chat(CoachRequest::prompt('hello'));

        $this->assertSame('Hi!', $response->content);
        $this->assertSame('claude-sonnet-4-6', $response->model);
        $this->assertSame(11, $response->promptTokens);
        $this->assertSame(7, $response->completionTokens);
        $this->assertSame('end_turn', $response->finishReason);
    }

    public function test_it_concatenates_multiple_text_blocks_and_ignores_non_text()
    {
        Http::fake(['*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => 'one '],
                ['type' => 'tool_use', 'id' => 'abc'],
                ['type' => 'text', 'text' => 'two'],
            ],
        ], 200)]);

        $this->assertSame('one two', $this->driver()->chat(CoachRequest::prompt('x'))->content);
    }

    public function test_it_sends_the_merged_system_prompt_json_instruction_and_auth_headers()
    {
        Http::fake(['*' => Http::response($this->okBody(), 200)]);

        $request = new CoachRequest(
            messages: [Message::user('author a loop')],
            system: 'You are a coach',
            json: true,
        );

        $this->driver()->chat($request);

        Http::assertSent(function (Request $sent) {
            $body = $sent->data();

            return $sent->url() === 'https://api.anthropic.com/v1/messages'
                && $sent->hasHeader('x-api-key', 'sk-test')
                && $sent->hasHeader('anthropic-version', '2023-06-01')
                && str_contains($body['system'], 'You are a coach')
                && str_contains($body['system'], 'single valid JSON')
                && $body['messages'] === [['role' => 'user', 'content' => 'author a loop']];
        });
    }

    public function test_the_api_version_header_can_be_overridden_by_config()
    {
        Http::fake(['*' => Http::response($this->okBody(), 200)]);

        $driver = new AnthropicCoachService(app(HttpFactory::class), [
            ...$this->config,
            'version' => '2099-01-01',
        ]);

        $driver->chat(CoachRequest::prompt('hello'));

        Http::assertSent(fn (Request $sent) => $sent->hasHeader('anthropic-version', '2099-01-01'));
    }

    public function test_missing_credentials_throws_before_any_request()
    {
        Http::fake();

        $driver = new AnthropicCoachService(app(HttpFactory::class), [
            'key' => null, 'model' => 'm', 'base_url' => 'https://x',
        ]);

        $this->expectException(CoachException::class);

        try {
            $driver->chat(CoachRequest::prompt('hello'));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_provider_error_status_throws()
    {
        Http::fake(['*' => Http::response(['error' => 'nope'], 429)]);

        $this->expectException(CoachException::class);
        $this->driver()->chat(CoachRequest::prompt('hello'));
    }

    public function test_an_empty_content_response_throws()
    {
        Http::fake(['*' => Http::response(['content' => []], 200)]);

        $this->expectException(CoachException::class);
        $this->driver()->chat(CoachRequest::prompt('hello'));
    }
}
