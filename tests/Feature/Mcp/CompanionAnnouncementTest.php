<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\ConcludeExperimentTool;
use App\Mcp\Tools\LogOutcomeTool;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * What the coach is handed about Blob, and — mostly — what it is not.
 *
 * The gate is the design: a companion line goes out only when the write moved
 * Blob up a stage. Every other log returns nothing at all, because a line after
 * every logged breakfast is wallpaper inside a week.
 */
class CompanionAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-27 09:00:00');
    }

    /**
     * @return array<mixed>
     */
    private function payload(TestResponse $response): array
    {
        $content = new \ReflectionMethod($response, 'content');

        /** @var array<int, string> $text */
        $text = $content->invoke($response);

        return json_decode($text[0], true, flags: JSON_THROW_ON_ERROR);
    }

    private function actionFor(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user))
            ->create([
                'status' => Action::STATUS_ACTIVE,
                'recurrence' => null,
                'series_started_at' => null,
            ]);
    }

    /**
     * @return array<mixed> The tool's response payload.
     */
    private function logOne(User $user, Action $action, int $daysAgo): array
    {
        $occurrence = Occurrence::factory()->create([
            'action_id' => $action->id,
            'scheduled_for' => now()->subDays($daysAgo)->setTime(19, 0),
        ]);

        $response = PatYourSelfServer::actingAs($user)->tool(LogOutcomeTool::class, [
            'occurrence_id' => $occurrence->id,
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ]);

        $response->assertOk();

        return $this->payload($response);
    }

    /** Outcomes written straight to the table — the setup, not the thing under test. */
    private function backfillOutcomes(User $user, int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            ActionLog::factory()->create([
                'user_id' => $user->id,
                'logged_at' => now()->subDays(30 - $index),
            ]);
        }
    }

    public function test_the_first_outcome_announces_that_blob_exists(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $payload = $this->logOne($user, $this->actionFor($user), 3);

        $this->assertArrayHasKey('companion', $payload);
        $this->assertSame('blob', $payload['companion']['unlocked']);
        $this->assertSame('body', $payload['companion']['kind']);
        $this->assertSame(route('companion'), $payload['companion']['url']);
    }

    /**
     * The message is the app's, written in config, and relayed as written. The
     * coach never composes the praise — that separation is the whole reason the
     * copy lives on this side.
     *
     * "As written" now means with the {name} token resolved, which the resolver
     * does once before anything sees the string. The token must never reach the
     * coach: it would relay it verbatim, which is the contract, and the reader
     * would see the braces.
     */
    public function test_the_message_is_the_config_copy_verbatim(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $payload = $this->logOne($user, $this->actionFor($user), 3);

        $this->assertSame(
            str_replace('{name}', 'Blob', config('companion.ladder.0.message')),
            $payload['companion']['message'],
        );
        $this->assertStringNotContainsString('{name}', $payload['companion']['message']);
    }

    /** A renamed companion is named in what the coach relays, too. */
    public function test_a_renamed_companion_is_named_in_what_the_coach_relays(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $user->companion()->firstOrCreate([])->update(['name' => 'Pebble']);

        $payload = $this->logOne($user, $this->actionFor($user), 3);

        $this->assertStringStartsWith('Pebble is here.', $payload['companion']['message']);
        $this->assertStringNotContainsString('Blob', $payload['companion']['message']);
    }

    /**
     * The payload names the companion as well as quoting it. The message reads
     * correctly without this, but the coach writes its own words around the
     * payload and has no other way to learn what to call the thing.
     */
    public function test_the_payload_tells_the_coach_what_the_companion_is_called(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $this->assertSame('Blob', $this->logOne($user, $this->actionFor($user), 3)['companion']['name']);

        $named = User::factory()->create(['timezone' => 'UTC']);
        $named->companion()->firstOrCreate([])->update(['name' => 'Pebble']);

        $this->assertSame('Pebble', $this->logOne($named, $this->actionFor($named), 3)['companion']['name']);
    }

    public function test_an_outcome_that_moves_nothing_says_nothing(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->actionFor($user);

        $this->logOne($user, $action, 5);
        $second = $this->logOne($user, $action, 4);

        // Between the first and third outcome Blob does not move, so the key is
        // absent entirely rather than present and empty.
        $this->assertArrayNotHasKey('companion', $second);

        $third = $this->logOne($user, $action, 3);

        $this->assertSame('legs', $third['companion']['unlocked']);
    }

    public function test_concluding_an_experiment_announces_the_stage_it_crosses(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->backfillOutcomes($user, 5);

        $loop = Intention::factory()->for($user)->create();
        Strategy::factory()->for($loop)->create([
            'version' => 1,
            'status' => Strategy::STATUS_ACTIVE,
        ]);

        $response = PatYourSelfServer::actingAs($user)->tool(ConcludeExperimentTool::class, [
            'intention_id' => $loop->id,
            'verdict' => Strategy::VERDICT_FAILED,
            'note' => 'The cue never fired on weekends.',
        ]);

        $response->assertOk();
        $payload = $this->payload($response);

        // A failed verdict is a conclusion like any other: reaching one is the
        // insight, and Blob does not grade it.
        $this->assertSame('walk', $payload['companion']['unlocked']);
        $this->assertSame('ability', $payload['companion']['kind']);
    }

    public function test_concluding_says_nothing_when_blob_has_not_moved(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $loop = Intention::factory()->for($user)->create();
        Strategy::factory()->for($loop)->create([
            'version' => 1,
            'status' => Strategy::STATUS_ACTIVE,
        ]);

        $response = PatYourSelfServer::actingAs($user)->tool(ConcludeExperimentTool::class, [
            'intention_id' => $loop->id,
            'verdict' => Strategy::VERDICT_INCONCLUSIVE,
        ]);

        $response->assertOk();

        // The insight counts, but the ladder is walked in order and this user
        // has never logged an outcome, so Blob has not appeared to be dressed.
        $this->assertArrayNotHasKey('companion', $this->payload($response));
    }
}
