<?php

namespace Tests\Feature\Export;

use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_it_downloads_the_record_as_json_by_default(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create(['title' => 'morning press-ups']);

        $response = $this->actingAs($user)->get(route('export.show'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/json');
        $this->assertStringContainsString('attachment;', $response->headers->get('content-disposition'));

        $record = json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('morning press-ups', $record['loops'][0]['title']);
    }

    public function test_it_downloads_the_record_as_markdown_when_asked(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create(['title' => 'morning press-ups']);

        $response = $this->actingAs($user)->get(route('export.show', ['format' => 'md']));

        $response->assertOk();
        $this->assertStringContainsString('text/markdown', $response->headers->get('content-type'));
        $this->assertStringContainsString('morning press-ups', $response->streamedContent());
    }

    public function test_an_unknown_format_falls_back_to_json(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('export.show', ['format' => 'pdf']));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/json');
        json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_a_verbatim_failure_reason_survives_the_round_trip(): void
    {
        $reason = '  I skipped it and told myself it was fine  ';

        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop, 'intention')->create();
        $occurrence = $action->occurrences()->create(['scheduled_for' => now()->subDay()]);
        $occurrence->log()->create([
            'user_id' => $user->id,
            'action_id' => $action->id,
            'outcome' => 'failed',
            'reason' => $reason,
            'logged_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($user)->get(route('export.show'));

        $record = json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($reason, $record['loops'][0]['actions'][0]['occurrences'][0]['outcome']['reason']);
    }

    public function test_a_guest_cannot_export(): void
    {
        $this->get(route('export.show'))->assertRedirect(route('login'));
    }

    public function test_an_empty_account_exports_a_valid_document(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('export.show'));

        $response->assertOk();
        $record = json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $record['loops']);
    }
}
