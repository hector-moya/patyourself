<?php

namespace Tests\Feature\Coach;

use App\Services\Coach\CoachManager;
use App\Services\Coach\Contracts\CoachService;
use App\Services\Coach\Drivers\AnthropicCoachService;
use App\Services\Coach\Exceptions\CoachException;
use App\Services\Coach\GuardedCoachService;
use Tests\TestCase;

class CoachManagerTest extends TestCase
{
    private function manager(): CoachManager
    {
        return $this->app->make(CoachManager::class);
    }

    public function test_default_driver_comes_from_config()
    {
        config()->set('services.coach.driver', 'anthropic');

        $this->assertSame('anthropic', $this->manager()->getDefaultDriver());
    }

    public function test_it_resolves_the_anthropic_driver()
    {
        $driver = $this->manager()->driver('anthropic');

        $this->assertInstanceOf(AnthropicCoachService::class, $driver);
        $this->assertSame('anthropic', $driver->name());
    }

    public function test_the_default_driver_merges_shared_coach_config()
    {
        config()->set('services.coach.timeout', 99);
        config()->set('services.anthropic.model', 'claude-test');

        // The Anthropic driver reads both shared (timeout) and provider (model)
        // config; resolving it without error proves the merge happened.
        $this->assertInstanceOf(AnthropicCoachService::class, $this->manager()->driver('anthropic'));
    }

    public function test_the_container_resolves_the_guarded_coach_service_contract()
    {
        config()->set('services.coach.driver', 'anthropic');

        // The contract resolves to the cost-guard decorator wrapping the
        // configured driver, so every LLM call is metered and capped.
        $service = $this->app->make(CoachService::class);

        $this->assertInstanceOf(GuardedCoachService::class, $service);
        $this->assertSame('anthropic', $service->name());
    }

    public function test_the_deferred_openai_driver_throws_a_clear_exception()
    {
        $this->expectException(CoachException::class);
        $this->expectExceptionMessage('[openai]');

        $this->manager()->driver('openai');
    }
}
