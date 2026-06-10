<?php

namespace App\Ai\Middleware;

use App\Models\User;
use App\Services\Coach\Data\CoachResponse;
use App\Services\Coach\Usage\CoachUsageGuard;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

/**
 * The cost guard as agent middleware: checks the authenticated user's rolling
 * token budget before the call and records the tokens spent after. Attached to
 * every agent, so each LLM call in a turn — orchestrator and specialists alike —
 * is metered into the coach_usages ledger. No authenticated user (console,
 * queued jobs) passes through unmetered, matching the old GuardedCoachService.
 */
class GuardCoachUsage
{
    public function __construct(private readonly AuthFactory $auth) {}

    public function handle(object $prompt, Closure $next)
    {
        $user = $this->auth->guard()->user();

        if (! $user instanceof User) {
            return $next($prompt);
        }

        $guard = $this->guard();
        $guard->ensureWithinBudget($user);

        $response = $next($prompt);
        $purpose = class_basename($prompt->agent);

        $guard->record($user, new CoachResponse(
            content: '',
            model: $response->meta->model ?? 'unknown',
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
        ), strtolower($purpose));

        return $response;
    }

    private function guard(): CoachUsageGuard
    {
        return new CoachUsageGuard(
            (int) config('services.coach.daily_token_budget', 0),
        );
    }
}
