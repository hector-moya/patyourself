<?php

namespace App\Services\Coach\Strategy;

use App\Models\Strategy;
use App\Services\Coach\Authoring\AuthoredStrategy;
use App\Services\Coach\Contracts\CoachService;
use App\Services\Coach\Data\CoachRequest;
use App\Services\Coach\Data\Message;
use App\Services\Coach\Exceptions\CoachException;

/**
 * Authors the *next* version of a strategy with the LLM. Given the current
 * strategy, the loop it belongs to, and the outcome (success, or failure with a
 * user-stated reason), it asks the coach for a revised intervention and returns
 * it as validated data — the "AI authors data" half of the coaching loop.
 *
 * It performs no persistence (the ReviseStrategy action does) and codes only
 * against the CoachService interface, so the LLM vendor stays swappable.
 */
final readonly class StrategyReviser
{
    public function __construct(private CoachService $coach) {}

    /**
     * The current strategy succeeded — author a harder next version.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws CoachException
     * @throws StrategyTransitionException
     */
    public function stack(Strategy $current, array $context = []): AuthoredStrategy
    {
        return $this->revise($current, StrategyRevisionSchema::MODE_STACK, null, $context);
    }

    /**
     * The current strategy failed — read the reason and author a revision that
     * moves the intervention point along the chain.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws CoachException
     * @throws StrategyTransitionException
     */
    public function restrategize(Strategy $current, string $reason, array $context = []): AuthoredStrategy
    {
        return $this->revise($current, StrategyRevisionSchema::MODE_RESTRATEGIZE, $reason, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws CoachException
     * @throws StrategyTransitionException
     */
    private function revise(Strategy $current, string $mode, ?string $reason, array $context): AuthoredStrategy
    {
        $request = new CoachRequest(
            messages: [Message::user($this->userPrompt($current, $mode, $reason, $context))],
            system: StrategyRevisionSchema::instructions($mode),
            temperature: 0.4,
            json: true,
            metadata: ['purpose' => 'strategy_revision', 'mode' => $mode],
        );

        $data = StrategyRevisionSchema::validate($this->coach->chat($request)->json());

        return AuthoredStrategy::fromValidated($data);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function userPrompt(Strategy $current, string $mode, ?string $reason, array $context): string
    {
        $intention = $current->intention;

        $lines = [
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
            $mode === StrategyRevisionSchema::MODE_STACK
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
}
