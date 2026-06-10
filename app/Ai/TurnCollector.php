<?php

namespace App\Ai;

/**
 * Request-scoped collector for data the coach's tools create mid-turn. The
 * CreateLoop tool registers each authored Intention id here; after the turn the
 * ChatController drains it to build the cards payload. Scoped (not singleton)
 * so queued/octane requests never leak ids across turns.
 */
class TurnCollector
{
    /** @var list<int> */
    private array $intentionIds = [];

    public function addIntention(int $id): void
    {
        $this->intentionIds[] = $id;
    }

    /** @return list<int> */
    public function intentionIds(): array
    {
        return $this->intentionIds;
    }

    public function flush(): void
    {
        $this->intentionIds = [];
    }
}
