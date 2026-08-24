<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\SesTransport;
use Illuminate\Support\Facades\Mail;
use Laravel\Fortify\Features;
use Tests\TestCase;

/**
 * The delivery half of email verification: that SES is a usable transport, and
 * that the verification mail actually renders and carries a working link.
 */
class VerificationMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::emailVerification());
    }

    /**
     * Guards the aws/aws-sdk-php requirement. The SDK arrived transitively via
     * laravel/ai before it was required directly, so without this a dependency
     * bump elsewhere could silently take SES down in production.
     */
    public function test_the_ses_mailer_resolves(): void
    {
        config()->set('services.ses', [
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'ap-southeast-2',
        ]);

        $this->assertInstanceOf(
            SesTransport::class,
            Mail::mailer('ses')->getSymfonyTransport(),
        );
    }

    /**
     * Renders the notification for real through the array transport — a fake
     * would assert it was dispatched without proving the mail builds.
     */
    public function test_the_verification_email_renders_with_a_signed_link(): void
    {
        config()->set('mail.default', 'array');

        $user = User::factory()->unverified()->create();

        $user->sendEmailVerificationNotification();

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();

        $this->assertCount(1, $messages);

        // Quoted-printable wraps long URLs mid-string, so decode before matching.
        $body = quoted_printable_decode($messages[0]->getOriginalMessage()->toString());

        $this->assertStringContainsString($user->email, $body);
        $this->assertStringContainsString('/email/verify/'.$user->id.'/'.sha1($user->email), $body);
        $this->assertStringContainsString('signature=', $body);
    }
}
