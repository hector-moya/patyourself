<?php

namespace Tests\Unit\Coach;

use App\Services\Coach\Authoring\AuthoredIntention;
use PHPUnit\Framework\TestCase;

class AuthoredIntentionActionTest extends TestCase
{
    public function test_parses_the_action_block(): void
    {
        $authored = AuthoredIntention::fromStructured([
            'title' => 'Morning walk',
            'type' => 'build',
            'cue' => 'Coffee finishes',
            'craving' => 'Feel awake',
            'response' => 'Walk 15 min',
            'reward' => 'Energy',
            'strategy' => ['intervention_point' => 'cue', 'approach' => 'Shoes by the door'],
            'action' => [
                'title' => 'Put shoes by the door',
                'schedule' => ['kind' => 'clock', 'time' => '07:00', 'recurrence' => 'daily'],
            ],
        ], 'haiku', 'intention-authoring@1');

        $this->assertNotNull($authored->action);
        $this->assertSame('Put shoes by the door', $authored->action->title);
        $this->assertSame('07:00', $authored->action->time);
    }

    public function test_action_is_null_when_absent(): void
    {
        $authored = AuthoredIntention::fromStructured([
            'title' => 'Morning walk',
            'type' => 'build',
            'cue' => 'c', 'craving' => 'c', 'response' => 'r', 'reward' => 'r',
        ], 'haiku');

        $this->assertNull($authored->action);
    }
}
