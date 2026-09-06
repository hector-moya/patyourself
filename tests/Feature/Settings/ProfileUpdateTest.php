<?php

namespace Tests\Feature\Settings;

use App\Models\Action;
use App\Models\ActionExercise;
use App\Models\Exercise;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\PerformedSet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    /**
     * Two of this branch's own foreign keys point at each other across the user.
     * `exercises.user_id` cascades when the user goes; `performed_sets.exercise_id`
     * and `action_exercises.exercise_id` both restrict when an exercise goes. So a
     * user who added their own exercise, put it in a routine and lifted against it
     * holds rows that forbid the deletion of a row their own deletion demands —
     * and which of the sibling cascades out of `users` the engine walks first is
     * undefined, so this is not a question the schema can answer.
     *
     * The restricts stay: they are the spec's "history does not vanish" rule, and
     * what stops tidying up a catalogue name from silently emptying somebody's
     * training record. The deletion path is what has to be explicit about order.
     *
     * Both restricts are exercised here on purpose. The sets are the pair that was
     * found; the routine rows are the identical shape one table over, and a fix
     * that only knew about the first would pass a test that only knew about it too.
     */
    public function test_user_can_delete_their_account_after_lifting_against_their_own_exercise()
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['user_id' => $user->id]);
        $action = Action::factory()->for(Intention::factory()->for($user))->create();
        $occurrence = Occurrence::factory()->for($action)->create();

        ActionExercise::factory()->create([
            'action_id' => $action->id,
            'exercise_id' => $exercise->id,
        ]);

        PerformedSet::factory()->count(3)->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $exercise->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();

        // Gone, and still gone: signing out happens after the delete now, and
        // SessionGuard::logout() re-saves a user that carries a remember token —
        // which on an already-deleted model is an INSERT. The factory gives every
        // user one, so this assertion is the guard against resurrecting them.
        $this->assertNull($user->fresh());

        // Everything of theirs goes with them. Asserted rather than assumed: a
        // path that bought the delete by orphaning the sets would satisfy the
        // assertions above and leave the record behind.
        $this->assertSame(0, Exercise::count());
        $this->assertSame(0, PerformedSet::count());
        $this->assertSame(0, ActionExercise::count());
        $this->assertSame(0, Occurrence::count());
    }

    public function test_a_deletion_the_database_refuses_leaves_the_user_signed_in()
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        // Another account's set against this user's exercise. Here the restrict
        // is doing its job — deleting would rewrite somebody else's history — so
        // the request must fail. What this pins is how it fails: the account is
        // torn down inside a transaction and the session is only touched once
        // that has returned, so a refusal leaves the user exactly where they were
        // rather than signed out of an account that is still there.
        $exercise = Exercise::factory()->create(['user_id' => $user->id]);
        $occurrence = Occurrence::factory()
            ->for(Action::factory()->for(Intention::factory()->for($other)))
            ->create();

        PerformedSet::factory()->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $exercise->id,
        ]);

        $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertServerError();

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh());

        // And the transaction took nothing with it on the way out.
        $this->assertSame(1, Exercise::count());
        $this->assertSame(1, PerformedSet::count());
        $this->assertSame(1, Occurrence::count());
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
    }
}
