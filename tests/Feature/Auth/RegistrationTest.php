<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    /**
     * Every schedule in the app derives from this, and the settings screen used
     * to be its only writer — so a new account ran silently on
     * config('app.timezone') until someone thought to go and look.
     */
    public function test_registering_captures_the_browsers_timezone(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'timezone' => 'Europe/London',
        ]);

        $this->assertSame('Europe/London', User::firstWhere('email', 'test@example.com')->timezone);
    }

    /**
     * `timezone_identifiers_list()` drops backward aliases, and browsers do
     * still report them. Rejecting one would be worse than useless.
     */
    public function test_a_legacy_timezone_alias_is_still_captured(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'timezone' => 'US/Pacific',
        ]);

        $this->assertSame('US/Pacific', User::firstWhere('email', 'test@example.com')->timezone);
    }

    /**
     * The zone is a hint, never a gate. A browser reporting something this PHP
     * build has never heard of must not cost someone their account — they fall
     * back to the app default exactly as they did before it was captured.
     */
    public function test_an_unknown_timezone_is_dropped_rather_than_blocking_registration(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'timezone' => 'Mars/Olympus_Mons',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertNull(User::firstWhere('email', 'test@example.com')->timezone);
    }

    /** A form that sends no zone at all — SSR before hydration, or no JS. */
    public function test_registering_without_a_timezone_still_works(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'timezone' => '',
        ]);

        $this->assertAuthenticated();
        $this->assertNull(User::firstWhere('email', 'test@example.com')->timezone);
    }

    /**
     * Registering must email a verification link. Laravel's
     * SendEmailVerificationNotification listener only fires when the user is an
     * instance of the MustVerifyEmail *contract* — the trait on the framework's
     * base user is not enough.
     */
    public function test_registering_sends_a_verification_email(): void
    {
        Notification::fake();

        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'test@example.com')->sole();

        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    /**
     * The `verified` middleware on routes/web.php only bites when the user
     * implements the MustVerifyEmail contract; otherwise it waves everyone
     * through and the whole app is reachable unverified.
     */
    public function test_unverified_users_are_sent_to_the_verification_notice(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));
    }
}
