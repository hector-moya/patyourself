<?php

namespace Tests\Unit\Coach;

use App\Services\Coach\Data\CoachRequest;
use App\Services\Coach\Data\Message;
use App\Services\Coach\Data\Role;
use PHPUnit\Framework\TestCase;

class CoachRequestTest extends TestCase
{
    public function test_prompt_builds_a_single_user_turn()
    {
        $request = CoachRequest::prompt('Build me a loop', system: 'You are a coach', json: true);

        $this->assertCount(1, $request->messages);
        $this->assertSame(Role::User, $request->messages[0]->role);
        $this->assertSame('Build me a loop', $request->messages[0]->content);
        $this->assertSame('You are a coach', $request->system);
        $this->assertTrue($request->json);
    }

    public function test_prompt_forwards_per_request_overrides()
    {
        $request = CoachRequest::prompt('hi', model: 'claude-test', temperature: 0.1, maxTokens: 50);

        $this->assertSame('claude-test', $request->model);
        $this->assertSame(0.1, $request->temperature);
        $this->assertSame(50, $request->maxTokens);
    }

    public function test_prompt_leaves_overrides_null_by_default()
    {
        $request = CoachRequest::prompt('hi');

        $this->assertNull($request->model);
        $this->assertNull($request->temperature);
        $this->assertNull($request->maxTokens);
    }

    public function test_message_payload_excludes_system_turns_by_default()
    {
        $request = new CoachRequest(messages: [
            Message::system('be terse'),
            Message::user('hi'),
            Message::assistant('hello'),
        ]);

        $this->assertSame([
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'assistant', 'content' => 'hello'],
        ], $request->messagePayload());
    }

    public function test_message_payload_can_include_system_turns()
    {
        $request = new CoachRequest(messages: [
            Message::system('be terse'),
            Message::user('hi'),
        ]);

        $payload = $request->messagePayload(excludeSystem: false);

        $this->assertCount(2, $payload);
        $this->assertSame('system', $payload[0]['role']);
    }

    public function test_resolve_system_merges_system_field_and_system_messages_in_order()
    {
        $request = new CoachRequest(
            messages: [Message::system('rule one'), Message::user('hi'), Message::system('rule two')],
            system: 'base prompt',
        );

        $this->assertSame("base prompt\n\nrule one\n\nrule two", $request->resolveSystem());
    }

    public function test_resolve_system_is_null_when_there_is_nothing_to_merge()
    {
        $request = new CoachRequest(messages: [Message::user('hi')]);

        $this->assertNull($request->resolveSystem());
    }
}
