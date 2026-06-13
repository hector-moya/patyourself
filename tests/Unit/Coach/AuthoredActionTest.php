<?php

namespace Tests\Unit\Coach;

use App\Services\Coach\Authoring\AuthoredAction;
use App\Services\Coach\Exceptions\CoachException;
use PHPUnit\Framework\TestCase;

class AuthoredActionTest extends TestCase
{
    public function test_parses_a_clock_action(): void
    {
        $action = AuthoredAction::fromStructured([
            'title' => 'Set your shoes by the door',
            'description' => 'A visible cue.',
            'schedule' => ['kind' => 'clock', 'time' => '07:00', 'recurrence' => 'daily'],
        ]);

        $this->assertSame('Set your shoes by the door', $action->title);
        $this->assertSame('clock', $action->kind);
        $this->assertSame('07:00', $action->time);
        $this->assertSame('daily', $action->recurrence);
        $this->assertNull($action->anchor);
    }

    public function test_parses_an_anchored_action(): void
    {
        $action = AuthoredAction::fromStructured([
            'title' => 'Do ten push-ups',
            'schedule' => ['kind' => 'anchored', 'anchor' => 'after morning coffee'],
        ]);

        $this->assertSame('anchored', $action->kind);
        $this->assertSame('after morning coffee', $action->anchor);
        $this->assertNull($action->time);
        $this->assertNull($action->recurrence);
    }

    public function test_absent_block_returns_null(): void
    {
        $this->assertNull(AuthoredAction::fromStructured(null));
        $this->assertNull(AuthoredAction::fromStructured([]));
    }

    public function test_rejects_a_bad_clock_time(): void
    {
        $this->expectException(CoachException::class);

        AuthoredAction::fromStructured([
            'title' => 'x',
            'schedule' => ['kind' => 'clock', 'time' => '7am', 'recurrence' => 'daily'],
        ]);
    }

    public function test_rejects_anchored_without_anchor(): void
    {
        $this->expectException(CoachException::class);

        AuthoredAction::fromStructured(['title' => 'x', 'schedule' => ['kind' => 'anchored']]);
    }

    public function test_try_from_structured_swallows_malformed(): void
    {
        $this->assertNull(AuthoredAction::tryFromStructured(['title' => 'x', 'schedule' => ['kind' => 'nope']]));
        $this->assertNotNull(AuthoredAction::tryFromStructured([
            'title' => 'ok',
            'schedule' => ['kind' => 'clock', 'time' => '08:30', 'recurrence' => 'once'],
        ]));
    }
}
