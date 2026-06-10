<?php

namespace App\Actions;

use App\Ai\Agents\Strategist;
use App\Models\Strategy;
use App\Services\Coach\Authoring\AuthoredStrategy;
use App\Services\Coach\Exceptions\CoachException;
use App\Services\Coach\Strategy\BehavioralChain;
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
    /**
     * The current strategy succeeded — stack toward a harder goal.
     *
     * @param  AuthoredStrategy|null  $next  A pre-authored revision; when null the Strategist agent authors one.
     * @param  array<string, mixed>  $context
     *
     * @throws StrategyTransitionException
     * @throws CoachException
     */
    public function stackOnSuccess(Strategy $current, ?AuthoredStrategy $next = null, array $context = []): Strategy
    {
        $this->guardActive($current);
        $next ??= $this->revise($current, 'stack', null, $context);

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
     * @param  AuthoredStrategy|null  $next  A pre-authored revision; when null the Strategist agent authors one.
     * @param  array<string, mixed>  $context
     *
     * @throws StrategyTransitionException
     * @throws CoachException
     */
    public function restrategizeOnFailure(Strategy $current, string $reason, ?AuthoredStrategy $next = null, array $context = []): Strategy
    {
        $this->guardActive($current);
        $next ??= $this->revise($current, 'restrategize', $reason, $context);

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

    /**
     * Calls the Strategist agent and maps the structured response into an
     * AuthoredStrategy value object.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws CoachException
     */
    private function revise(Strategy $current, string $mode, ?string $reason, array $context): AuthoredStrategy
    {
        $userPrompt = $this->userPrompt($current, $mode, $reason, $context);
        $response = (new Strategist)->prompt($userPrompt);

        $interventionPoint = trim((string) ($response->structured['intervention_point'] ?? ''));
        $approach = trim((string) ($response->structured['approach'] ?? ''));

        if ($approach === '' || $interventionPoint === '') {
            throw CoachException::emptyResponse('strategist');
        }

        $validPoints = [Strategy::POINT_CUE, Strategy::POINT_CRAVING, Strategy::POINT_RESPONSE, Strategy::POINT_REWARD];
        if (! in_array($interventionPoint, $validPoints, strict: true)) {
            throw CoachException::emptyResponse('strategist');
        }

        return new AuthoredStrategy(
            interventionPoint: $interventionPoint,
            approach: $approach,
            rationale: isset($response->structured['rationale']) ? trim((string) $response->structured['rationale']) : null,
            promptVersion: Strategist::PROMPT_VERSION,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function userPrompt(Strategy $current, string $mode, ?string $reason, array $context): string
    {
        $intention = $current->intention;

        $lines = [
            'Mode: '.($mode === 'stack' ? 'STACK' : 'RESTRATEGIZE'),
            '',
            'Habit loop: '.$intention->title.' ('.$intention->type.')',
            'Cue: '.$intention->cue,
            'Craving: '.$intention->craving,
            'Response: '.$intention->response,
            'Reward: '.$intention->reward,
            '',
            'Current strategy (version '.$current->version.'):',
            'Intervention point: '.$current->intervention_point,
            'Approach: '.$current->approach,
            '',
            $mode === 'stack'
                ? 'Outcome: the user SUCCEEDED with this strategy. Stack toward a harder goal.'
                : 'Outcome: the user FAILED this strategy. Their stated reason: "'.trim((string) $reason).'"',
        ];

        if ($context !== []) {
            $lines[] = '';
            $lines[] = 'Additional context:';
            $lines[] = (string) json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $lines);
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
