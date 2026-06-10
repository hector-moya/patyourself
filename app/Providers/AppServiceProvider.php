<?php

namespace App\Providers;

use App\Ai\TurnCollector;
use App\Services\Coach\CoachManager;
use App\Services\Coach\Contracts\CoachService;
use App\Services\Coach\GuardedCoachService;
use App\Services\Coach\Usage\CoachUsageGuard;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
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

        // Provider-agnostic coaching engine. Resolve CoachService to get the
        // driver configured by services.coach.driver; the vendor stays swappable.
        // The driver is wrapped in the cost guard so every LLM call is metered
        // and capped against the user's rolling token budget in one place.
        $this->app->singleton(CoachManager::class);
        $this->app->singleton(CoachService::class, fn ($app) => new GuardedCoachService(
            $app->make(CoachManager::class)->driver(),
            new CoachUsageGuard(
                (int) $app->make('config')->get('services.coach.daily_token_budget', 0),
            ),
            $app->make(AuthFactory::class),
        ));
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
