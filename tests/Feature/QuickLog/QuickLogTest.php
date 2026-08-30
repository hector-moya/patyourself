<?php

namespace Tests\Feature\QuickLog;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class QuickLogTest extends TestCase
{
    use RefreshDatabase;

    private function occurrence(): Occurrence
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop, 'intention')->create(['status' => Action::STATUS_ACTIVE]);

        return $action->occurrences()->create([
            'scheduled_for' => now()->subHour(),
            'fired_at' => now()->subHour(),
        ]);
    }

    private function signedUrl(Occurrence $occurrence, string $outcome, ?string $expiry = null): string
    {
        return URL::temporarySignedRoute(
            'occurrences.quick-log',
            $expiry ? now()->parse($expiry) : now()->addDays(7),
            ['occurrence' => $occurrence->id, 'outcome' => $outcome],
        );
    }

    public function test_a_signed_link_logs_the_outcome_without_a_login(): void
    {
        $occurrence = $this->occurrence();

        $this->get($this->signedUrl($occurrence, 'completed'))->assertOk();

        $this->assertDatabaseHas('action_logs', [
            'occurrence_id' => $occurrence->id,
            'outcome' => 'completed',
        ]);
    }

    public function test_skipped_is_also_loggable_in_one_click(): void
    {
        $occurrence = $this->occurrence();

        $this->get($this->signedUrl($occurrence, 'skipped'))->assertOk();

        $this->assertDatabaseHas('action_logs', ['occurrence_id' => $occurrence->id, 'outcome' => 'skipped']);
    }

    public function test_failed_is_not_available_in_one_click(): void
    {
        $occurrence = $this->occurrence();

        $this->get($this->signedUrl($occurrence, 'failed'))->assertNotFound();

        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_an_unsigned_url_is_rejected(): void
    {
        $occurrence = $this->occurrence();

        $this->get("/o/{$occurrence->id}/completed")->assertForbidden();

        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_an_expired_link_is_rejected(): void
    {
        $occurrence = $this->occurrence();
        $url = $this->signedUrl($occurrence, 'completed');

        $this->travel(8)->days();

        $this->get($url)->assertForbidden();
        $this->assertDatabaseCount('action_logs', 0);
    }

    public function test_a_second_click_does_not_write_a_second_outcome(): void
    {
        $occurrence = $this->occurrence();
        $url = $this->signedUrl($occurrence, 'completed');

        $this->get($url)->assertOk();
        $this->get($url)->assertOk()->assertSee('already logged', false);

        $this->assertDatabaseCount('action_logs', 1);
    }

    /**
     * The signature covers the route parameters, including the occurrence id.
     * Swapping in a different occurrence after the fact must fail closed — with
     * no session, the signature *is* the authorization for which occasion gets
     * written.
     */
    public function test_a_signed_url_cannot_be_repointed_at_a_different_occurrence(): void
    {
        $occurrence = $this->occurrence();
        $otherOccurrence = $this->occurrence();

        $url = $this->signedUrl($occurrence, 'completed');
        $tampered = str_replace(
            "/o/{$occurrence->id}/completed",
            "/o/{$otherOccurrence->id}/completed",
            $url,
        );

        $this->get($tampered)->assertForbidden();

        $this->assertDatabaseCount('action_logs', 0);
    }
}
