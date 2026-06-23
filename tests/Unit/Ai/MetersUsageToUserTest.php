<?php

namespace Tests\Unit\Ai;

use App\Ai\Agents\Strategist;
use App\Ai\Agents\Summarizer;
use App\Models\User;
use Tests\TestCase;

class MetersUsageToUserTest extends TestCase
{
    public function test_strategist_carries_the_attributed_user(): void
    {
        $user = new User(['id' => 1]);
        $agent = new Strategist;

        $returned = $agent->forUser($user);

        $this->assertSame($agent, $returned, 'forUser should return the agent for chaining');
        $this->assertSame($user, $agent->conversationParticipant());
    }

    public function test_summarizer_carries_the_attributed_user(): void
    {
        $user = new User(['id' => 2]);
        $agent = (new Summarizer)->forUser($user);

        $this->assertSame($user, $agent->conversationParticipant());
    }

    public function test_participant_is_null_until_attributed(): void
    {
        $this->assertNull((new Strategist)->conversationParticipant());
        $this->assertNull((new Summarizer)->conversationParticipant());
    }
}
