<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Ai\Concerns\HasConversations;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'timezone', 'email_reminders', 'digest_time'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /**
     * This model deliberately does NOT also compose Laravel\Passport\HasApiTokens
     * or implement Laravel\Passport\Contracts\OAuthenticatable. Sanctum's and
     * Passport's HasApiTokens traits both declare a protected $accessToken
     * property with incompatible signatures (Sanctum's is untyped, Passport's
     * is `?ScopeAuthorizable`), which is a hard PHP fatal error when both
     * traits are used on the same class — `insteadof`/`as` only resolve
     * method conflicts, not property conflicts, so there is no way to compose
     * both traits here.
     *
     * This is safe to skip: Passport's `api` guard (config/auth.php) only
     * ever calls `withAccessToken()` on this model (see
     * Laravel\Passport\Guards\TokenGuard and Laravel\Passport\Passport::actingAs),
     * and its scope-checking middleware reads `currentAccessToken()` — both
     * already provided by Sanctum's trait below. Passport's token/scope
     * objects (AccessToken, TransientToken) implement their own can()/cant(),
     * which Sanctum's tokenCan() delegates to, so scope checks on an
     * OAuth-authenticated request work without any Passport-specific code
     * on the model.
     *
     * @use HasFactory<UserFactory>
     */
    use HasApiTokens, HasConversations, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** Email cues are off entirely; the in-app inbox still receives them. */
    public const EMAIL_REMINDERS_OFF = 'off';

    /** One email each day at digest_time, listing everything due. */
    public const EMAIL_REMINDERS_DIGEST = 'digest';

    /** One email per cue, at the moment the action fires. */
    public const EMAIL_REMINDERS_EVERY_CUE = 'every_cue';

    /** @var array<int, string> */
    public const EMAIL_REMINDER_MODES = [
        self::EMAIL_REMINDERS_OFF,
        self::EMAIL_REMINDERS_DIGEST,
        self::EMAIL_REMINDERS_EVERY_CUE,
    ];

    /**
     * These mirror the `users` migration's column defaults so a freshly
     * instantiated (not-yet-persisted-and-reloaded) model already reflects
     * them, instead of appearing null until the record is re-fetched.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'email_reminders' => self::EMAIL_REMINDERS_DIGEST,
        'digest_time' => '07:00',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'digest_last_sent_on' => 'date',
        ];
    }

    /** @return HasMany<Intention, $this> */
    public function intentions(): HasMany
    {
        return $this->hasMany(Intention::class);
    }

    /** @return HasMany<ActionLog, $this> */
    public function actionLogs(): HasMany
    {
        return $this->hasMany(ActionLog::class);
    }

    /** @return HasMany<Summary, $this> */
    public function summaries(): HasMany
    {
        return $this->hasMany(Summary::class);
    }

    /** @return HasMany<CompanionRemark, $this> */
    public function companionRemarks(): HasMany
    {
        return $this->hasMany(CompanionRemark::class);
    }
}
