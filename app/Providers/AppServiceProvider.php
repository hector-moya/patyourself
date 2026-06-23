<?php

namespace App\Providers;

use App\Ai\TurnCollector;
use App\Services\Coach\Usage\CoachUsageGuard;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Request-scoped collector; drained by ChatController after each coach turn.
        $this->app->scoped(TurnCollector::class);

        // The single cost guard, sourced from config so the middleware and the
        // progress dashboard share one construction. Bound (not singleton) so it
        // re-reads the budget per resolve — tests set it per case.
        $this->app->bind(
            CoachUsageGuard::class,
            fn (): CoachUsageGuard => new CoachUsageGuard((int) config('services.coach.daily_token_budget', 0)),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * The `coach` limiter caps how often a user can hit the LLM-backed routes
     * per minute (config services.coach.rate_per_minute; 0 disables it).
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('coach', function (Request $request): Limit {
            $perMinute = (int) config('services.coach.rate_per_minute', 0);

            if ($perMinute <= 0) {
                return Limit::none();
            }

            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute($perMinute)->by('coach:'.$key);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
