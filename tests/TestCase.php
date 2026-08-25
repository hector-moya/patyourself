<?php

namespace Tests;

use App\Ai\Agents\Strategist;
use App\Ai\Agents\Summarizer;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every agent is faked by default, and no test may reach the network.
     *
     * Coaching runs from a queued listener on ActionLogged, so tests that only
     * log an outcome still prompt an agent. Left un-faked that silently bills the
     * developer's ANTHROPIC_API_KEY locally and 401s in CI, where no key is set.
     *
     * A blanket fake with no canned responses returns schema-conforming generated
     * data, which is all these tests need. Any test asserting on specific coaching
     * output re-fakes the agent it cares about, which replaces the gateway here.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        Strategist::fake();
        Summarizer::fake();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
