<?php

namespace Tests\Feature\Companion;

use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Companion\CompanionResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Blob answers to a name.
 *
 * The column is the small part. The real work is that every authored line used
 * to hardcode the word — 13 ladder messages and 6 tail templates — and a
 * renamed companion referring to itself in the third person is the bug F1 §7
 * exists to prevent.
 *
 * Substitution happens ONCE, in the resolver, before anything sees the string.
 * The coach relays messages verbatim by contract, so a token reaching it is a
 * token the reader sees.
 */
class CompanionNameTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): CompanionResolver
    {
        return app(CompanionResolver::class);
    }

    /** Enough of a record to walk the authored ladder and two tail rungs. */
    private function deepRecord(User $user): void
    {
        $base = CarbonImmutable::parse('2026-06-01T09:00:00+00:00');

        for ($index = 0; $index < 5; $index++) {
            ActionLog::factory()->create([
                'user_id' => $user->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
                'logged_at' => $base->addDays($index),
            ]);
        }

        for ($index = 0; $index < 15; $index++) {
            Strategy::factory()
                ->for(Intention::factory()->for($user))
                ->create([
                    'verdict' => Strategy::VERDICT_WORKED,
                    'verdict_note' => 'What the evidence showed.',
                    'created_at' => $base->addDays(10)->addHours($index),
                    'updated_at' => $base->addDays(10)->addHours($index),
                ]);
        }
    }

    /**
     * Nothing changes until someone renames. Asserted against the literal
     * strings the pre-token copy used, so a substitution that silently dropped
     * a word would fail here rather than pass by being self-consistent.
     */
    public function test_an_unnamed_companion_reads_exactly_as_it_did_before(): void
    {
        $user = User::factory()->create();
        $this->deepRecord($user);

        $messages = array_column($this->resolver()->forUser($user)->unlocks, 'message');

        $this->assertStringStartsWith('Blob is here.', $messages[0]);
        $this->assertSame('Blob has legs now. Standing up took most of the day and Blob considers it a fine use of one.', $messages[1]);
        $this->assertSame('Blob has arms now. It has not decided what they are for.', $messages[2]);
        $this->assertSame('Blob can walk. Slowly, and so far in one direction only.', $messages[4]);
    }

    public function test_a_renamed_companion_is_named_in_every_message(): void
    {
        $user = User::factory()->create();
        $user->companion()->firstOrCreate([])->update(['name' => 'Pebble']);
        $this->deepRecord($user);

        $state = $this->resolver()->forUser($user);

        $this->assertSame('Pebble', $state->name);

        foreach ($state->unlocks as $unlock) {
            $this->assertStringNotContainsString('Blob', $unlock['message']);
        }

        $this->assertStringStartsWith('Pebble is here.', $state->unlocks[0]['message']);
    }

    /**
     * The tail is the half that matters most — longest life, least supervision,
     * and its rungs ship without anyone reading them.
     */
    public function test_the_tail_is_named_too(): void
    {
        $user = User::factory()->create();
        $user->companion()->firstOrCreate([])->update(['name' => 'Pebble']);
        $this->deepRecord($user);

        $unlocks = $this->resolver()->forUser($user)->unlocks;

        // Past the 13 authored rungs: the tail.
        $this->assertGreaterThan(13, count($unlocks));

        foreach (array_slice($unlocks, 13) as $unlock) {
            $this->assertStringContainsString('Pebble', $unlock['message']);
            $this->assertStringNotContainsString('{name}', $unlock['message']);
        }
    }

    /** No token ever survives to a surface, named or not. */
    public function test_the_token_never_reaches_a_reader(): void
    {
        foreach ([null, 'Pebble'] as $name) {
            $user = User::factory()->create();
            $user->companion()->firstOrCreate([])->update(['name' => $name]);
            $this->deepRecord($user);

            $payload = $this->resolver()->forUser($user)->toArray();

            $this->assertStringNotContainsString(
                '{name}',
                json_encode($payload, JSON_THROW_ON_ERROR),
                'a {name} token survived into the payload',
            );
        }
    }

    /** Null, and whitespace, both read as "Blob". Clearing is not destructive. */
    public function test_an_empty_name_falls_back_rather_than_rendering_blank(): void
    {
        foreach ([null, '', '   '] as $name) {
            $user = User::factory()->create();
            $user->companion()->firstOrCreate([])->update(['name' => $name]);
            $this->deepRecord($user);

            $state = $this->resolver()->forUser($user);

            $this->assertSame('Blob', $state->name);
            $this->assertStringStartsWith('Blob is here.', $state->unlocks[0]['message']);
        }
    }

    /** The state carries the name so the client can write its own copy. */
    public function test_the_payload_carries_the_name(): void
    {
        $user = User::factory()->create();
        $user->companion()->firstOrCreate([])->update(['name' => 'Pebble']);

        $this->assertSame('Pebble', $this->resolver()->forUser($user)->toArray()['name']);
    }

    /** One person's name is never another's. */
    public function test_another_users_name_is_not_this_one(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $stranger->companion()->firstOrCreate([])->update(['name' => 'Pebble']);

        $this->deepRecord($user);

        $this->assertSame('Blob', $this->resolver()->forUser($user)->name);
    }
}
