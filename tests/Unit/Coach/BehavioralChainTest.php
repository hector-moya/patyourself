<?php

namespace Tests\Unit\Coach;

use App\Models\Strategy;
use App\Services\Coach\Strategy\BehavioralChain;
use PHPUnit\Framework\TestCase;

class BehavioralChainTest extends TestCase
{
    public function test_moving_later_down_the_chain(): void
    {
        $this->assertSame('later', BehavioralChain::direction(Strategy::POINT_CUE, Strategy::POINT_REWARD));
        $this->assertSame('later', BehavioralChain::direction(Strategy::POINT_CRAVING, Strategy::POINT_RESPONSE));
    }

    public function test_moving_earlier_up_the_chain(): void
    {
        $this->assertSame('earlier', BehavioralChain::direction(Strategy::POINT_REWARD, Strategy::POINT_CUE));
        $this->assertSame('earlier', BehavioralChain::direction(Strategy::POINT_RESPONSE, Strategy::POINT_CRAVING));
    }

    public function test_same_point_is_not_a_move(): void
    {
        $this->assertSame('same', BehavioralChain::direction(Strategy::POINT_RESPONSE, Strategy::POINT_RESPONSE));
    }
}
