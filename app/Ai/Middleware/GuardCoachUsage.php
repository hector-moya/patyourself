<?php

namespace App\Ai\Middleware;

use App\Models\User;
use App\Services\Coach\Usage\CoachUsageGuard;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Laravel\Ai\Prompts\AgentPrompt;

/**
 * The cost guard as agent middleware: checks the authenticated user's rolling
 * token budget before the call and records the tokens spent after. Attached to
 * every agent, so each LLM call in a turn — orchestrator and specialists alike —
 * is metered into the coach_usages ledger.
 *
 * Queued-agent calls carry no HTTP session, so `auth()->user()` returns null.
 * When that happens we fall back to the agent's conversation participant (set by
 * RemembersConversations) so queued agents are metered to the correct user.
 *
 * Calls with no authenticated user and no conversation participant (console smoke
 * tests) pass straight through unmetered.
 *
 * NOTE: if $next throws mid-tool-loop, partially accrued provider spend is not
 * recorded (SDK exposes no usage on failure) — accepted, same as the old decorator.
 */
class GuardCoachUsage
{
    public function __construct(private readonly AuthFactory $auth) {}

    public function handle(AgentPrompt $prompt, Closure $next)
    {
        $user = $this->auth->guard()->user();

        if (! $user instanceof User && method_exists($prompt->agent, 'conversationParticipant')) {
            $participant = $prompt->agent->conversationParticipant();
            $user = $participant instanceof User ? $participant : null;
        }

        if (! $user instanceof User) {
            return $next($prompt);
        }

        $guard = $this->guard();
        $guard->ensureWithinBudget($user);

        $purpose = strtolower(class_basename($prompt->agent));

        // NOTE: if $next throws mid-tool-loop, partially accrued provider spend is
        // not recorded (SDK exposes no usage on failure) — accepted, same as the old decorator.
        return $next($prompt)->then(function ($response) use ($guard, $user, $purpose) {
            $guard->record(
                $user,
                $response->meta->model ?? 'unknown',
                $response->usage->promptTokens,
                $response->usage->completionTokens,
                $purpose,
            );
        });
    }

    private function guard(): CoachUsageGuard
    {
        return new CoachUsageGuard(
            (int) config('services.coach.daily_token_budget', 0),
        );
    }
}
