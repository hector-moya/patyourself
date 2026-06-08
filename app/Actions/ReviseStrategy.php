<?php

namespace App\Actions;

use App\Models\Strategy;
use App\Services\Coach\Authoring\AuthoredStrategy;
use App\Services\Coach\Strategy\BehavioralChain;
use App\Services\Coach\Strategy\StrategyReviser;
use App\Services\Coach\Strategy\StrategyTransitionException;
use Illuminate\Support\Facades\DB;

/**
 * The heart of the coaching loop: advancing a strategy to its next version.
 *
 * History is never rewritten in place. Each transition supersedes the current
 * (active) version and creates a new active one — recording WHY (stacked on a
 * success / restrategized on a user-stated failure), WHERE in the behavioural
 * chain it now intervenes, and which direction it moved. This action is the
 * only place these writes happen.
 */
final readonly class ReviseStrategy
{
    public function __construct(private StrategyReviser $reviser) {}

    /**
     * The current strategy succeeded — stack toward a harder goal.
     *
     * @param  AuthoredStrategy|null  $next  A pre-authored revision; when null the coach authors one.
     * @param  array<string, mixed>  $context
     *
     * @throws StrategyTransitionException
     */
    public function stackOnSuccess(Strategy $current, ?AuthoredStrategy $next = null, array $context = []): Strategy
    {
        $this->guardActive($current);
        $next ??= $this->reviser->stack($current, $context);

        return DB::transaction(fn (): Strategy => $this->supersedeAndCreate(
            $current,
            $next,
            Strategy::REASON_STACKED_ON_SUCCESS,
            supersededReason: null,
        ));
    }

    /**
     * The current strategy failed — restrategize from the user-stated reason.
     *
     * @param  AuthoredStrategy|null  $next  A pre-authored revision; when null the coach authors one.
     * @param  array<string, mixed>  $context
     *
     * @throws StrategyTransitionException
     */
    public function restrategizeOnFailure(Strategy $current, string $reason, ?AuthoredStrategy $next = null, array $context = []): Strategy
    {
        $this->guardActive($current);
        $next ??= $this->reviser->restrategize($current, $reason, $context);

        return DB::transaction(fn (): Strategy => $this->supersedeAndCreate(
            $current,
            $next,
            Strategy::REASON_RESTRATEGIZED_ON_FAILURE,
            supersededReason: $reason,
        ));
    }

    /**
     * @throws StrategyTransitionException
     */
    private function guardActive(Strategy $current): void
    {
        if ($current->status !== Strategy::STATUS_ACTIVE) {
            throw StrategyTransitionException::notActive($current);
        }
    }

    private function supersedeAndCreate(
        Strategy $current,
        AuthoredStrategy $next,
        string $changeReason,
        ?string $supersededReason,
    ): Strategy {
        $current->update([
            'status' => Strategy::STATUS_SUPERSEDED,
            'superseded_reason' => $supersededReason,
        ]);

        $nextVersion = (int) $current->intention->strategies()->max('version') + 1;

        return $current->intention->strategies()->create([
            'version' => $nextVersion,
            'status' => Strategy::STATUS_ACTIVE,
            'intervention_point' => $next->interventionPoint,
            'approach' => $next->approach,
            'rationale' => $next->rationale,
            'parent_strategy_id' => $current->id,
            'change_reason' => $changeReason,
            'metadata' => array_filter([
                'previous_point' => $current->intervention_point,
                'direction' => BehavioralChain::direction(
                    $current->intervention_point,
                    $next->interventionPoint,
                ),
                'prompt_version' => $next->promptVersion,
            ], static fn ($value): bool => $value !== null),
        ]);
    }
}
