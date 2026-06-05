<?php

namespace Tests\Feature\Console;

use App\Services\Coach\Contracts\CoachService;
use App\Services\Coach\Data\CoachRequest;
use App\Services\Coach\Data\CoachResponse;
use App\Services\Coach\Exceptions\CoachException;
use App\Services\Coach\FakeCoachService;
use Tests\TestCase;

class CoachPingTest extends TestCase
{
    public function test_it_prints_the_reply_from_the_configured_driver()
    {
        $fake = (new FakeCoachService)->push(new CoachResponse(
            content: 'Hello, friend.',
            model: 'fake-1',
            promptTokens: 5,
            completionTokens: 3,
        ));
        $this->app->instance(CoachService::class, $fake);

        $this->artisan('coach:ping', ['prompt' => 'say hi'])
            ->assertSuccessful()
            ->expectsOutputToContain('Hello, friend.');

        $this->assertSame('say hi', $fake->lastRequest()->messages[0]->content);
    }

    public function test_it_fails_gracefully_when_the_driver_errors()
    {
        $this->app->instance(CoachService::class, new class implements CoachService
        {
            public function chat(CoachRequest $request): CoachResponse
            {
                throw CoachException::missingCredentials('anthropic');
            }

            public function name(): string
            {
                return 'anthropic';
            }
        });

        $this->artisan('coach:ping')->assertFailed();
    }
}
