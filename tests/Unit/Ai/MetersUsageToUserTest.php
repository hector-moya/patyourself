<?php

namespace Tests\Unit\Ai;

use App\Ai\Agents\Summarizer;
use App\Models\User;
use Tests\TestCase;

class MetersUsageToUserTest extends TestCase
{
    public function test_summarizer_carries_the_attributed_user(): void
    {
        $user = new User(['id' => 2]);
        $agent = new Summarizer;

        $returned = $agent->forUser($user);

        $this->assertSame($agent, $returned, 'forUser should return the agent for chaining');
        $this->assertSame($user, $agent->conversationParticipant());
    }

    public function test_participant_is_null_until_attributed(): void
    {
        $this->assertNull((new Summarizer)->conversationParticipant());
    }
}
