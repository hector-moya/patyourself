<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\Summarizer;
use Tests\TestCase;

class SummarizerTest extends TestCase
{
    public function test_returns_structured_content_and_patterns(): void
    {
        // SPIKE FINDING: fake() must receive a decoded PHP array, NOT a
        // json_encode() string. FakeTextGateway::marshalResponse() maps strings
        // to TextResponse (no ->structured property) and arrays to
        // StructuredTextResponse. GeneratesText::prompt() accesses
        // $response->structured for HasStructuredOutput agents, so passing a
        // JSON string causes "Undefined property: TextResponse::$structured".
        // Correct pattern: pass the array directly.
        Summarizer::fake([
            ['content' => 'Mornings keep failing.', 'patterns' => ['fails_on_mornings']],
        ]);

        $response = (new Summarizer)->prompt('Summarize these events: ...');

        // StructuredAgentResponse implements ArrayAccess via ProvidesStructuredResponse,
        // so $response['key'] maps to $response->structured['key'].
        $this->assertSame('Mornings keep failing.', $response['content']);
        $this->assertSame(['fails_on_mornings'], $response['patterns']);
    }

    public function test_carries_a_prompt_version(): void
    {
        $this->assertNotSame('', Summarizer::PROMPT_VERSION);
    }
}
