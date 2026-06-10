<?php

namespace Tests\Feature\Ai;

use App\Ai\TurnCollector;
use Tests\TestCase;

class TurnCollectorTest extends TestCase
{
    public function test_container_returns_same_accumulating_instance_within_scope(): void
    {
        $collector = $this->app->make(TurnCollector::class);
        $collector->addIntention(7);
        $collector->addIntention(9);

        $this->assertSame([7, 9], $this->app->make(TurnCollector::class)->intentionIds());
    }

    public function test_flush_empties_the_collector(): void
    {
        $collector = $this->app->make(TurnCollector::class);
        $collector->addIntention(7);
        $collector->flush();

        $this->assertSame([], $collector->intentionIds());
    }
}
