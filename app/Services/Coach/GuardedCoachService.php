<?php

namespace App\Services\Coach;

use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Coach\Contracts\CoachService;
use App\Services\Coach\Data\CoachRequest;
use App\Services\Coach\Data\CoachResponse;
use App\Services\Coach\Usage\CoachUsageGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

/**
 * Wraps a CoachService with the cost guard so spend is metered and capped in one
 * place: it checks the authenticated user's rolling token budget before each
 * call and records the tokens spent after. Calls with no authenticated user
 * (console commands, internal jobs) pass straight through unmetered.
 *
 * Bound over the real driver in {@see AppServiceProvider}, so
 * every LLM call site — chat, authoring, summaries, strategy — is covered.
 */
final readonly class GuardedCoachService implements CoachService
{
    public function __construct(
        private CoachService $inner,
        private CoachUsageGuard $guard,
        private AuthFactory $auth,
    ) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function chat(CoachRequest $request): CoachResponse
    {
        $user = $this->user();

        if ($user !== null) {
            $this->guard->ensureWithinBudget($user);
        }

        $response = $this->inner->chat($request);

        if ($user !== null) {
            $this->guard->record($user, $response, $this->purpose($request));
        }

        return $response;
    }

    private function user(): ?User
    {
        $user = $this->auth->guard()->user();

        return $user instanceof User ? $user : null;
    }

    private function purpose(CoachRequest $request): ?string
    {
        $purpose = $request->metadata['purpose'] ?? null;

        return is_string($purpose) ? $purpose : null;
    }
}
