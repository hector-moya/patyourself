<?php

namespace Tests\Feature\Progress;

use App\Models\User;
use App\Services\Coach\Usage\CoachUsageGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProgressUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_index_includes_the_coach_usage_snapshot(): void
    {
        config()->set('services.coach.daily_token_budget', 200000);

        $user = User::factory()->create();
        (new CoachUsageGuard(200000))->record($user, 'claude-haiku-4-5', 100, 50, 'summarizer');

        $this->actingAs($user)
            ->get(route('progress'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('progress/index')
                ->where('usage.used', 150)
                ->where('usage.budget', 200000)
                ->where('usage.remaining', 199850)
                ->where('usage.breakdown.summarizer', 150),
            );
    }
}
