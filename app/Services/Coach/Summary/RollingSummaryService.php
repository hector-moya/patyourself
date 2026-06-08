<?php

namespace App\Services\Coach\Summary;

use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Summary;
use App\Services\Coach\Contracts\CoachService;
use App\Services\Coach\Data\CoachRequest;
use App\Services\Coach\Data\Message;
use App\Services\Coach\Exceptions\CoachException;
use App\Services\Coach\Prompts\CoachPrompts;
use Illuminate\Support\Carbon;

/**
 * Folds a loop's prior rolling summary and its new action-log events into an
 * updated summary with detected behavioural patterns. The "AI authors data"
 * half of pattern detection: it builds the prompt, calls the coach, and
 * validates — it performs no persistence and reads only what it is handed, so
 * it codes purely against the CoachService interface.
 */
final readonly class RollingSummaryService
{
    public function __construct(private CoachService $coach) {}

    /**
     * @param  iterable<ActionLog>  $events  New events since the prior summary.
     * @param  Summary|null  $previous  The prior rolling summary, if any.
     *
     * @throws CoachException
     * @throws SummaryException
     */
    public function summarize(Intention $intention, iterable $events, ?Summary $previous): AuthoredSummary
    {
        $prompt = CoachPrompts::rollingSummary();

        $request = new CoachRequest(
            messages: [Message::user($this->userPrompt($intention, $events, $previous))],
            system: $prompt->system,
            // Summarising is a distillation task — keep it tight and factual.
            temperature: 0.3,
            json: true,
            metadata: ['purpose' => 'rolling_summary', 'intention_id' => $intention->id, 'prompt_version' => $prompt->version],
        );

        $response = $this->coach->chat($request);

        return AuthoredSummary::fromResponse($response->json(), $response, $prompt->version);
    }

    /**
     * @param  iterable<ActionLog>  $events
     */
    private function userPrompt(Intention $intention, iterable $events, ?Summary $previous): string
    {
        $lines = [
            'Habit loop: '.$intention->title.' ('.$intention->type.')',
            'Cue: '.$intention->cue,
            'Craving: '.$intention->craving,
            'Response: '.$intention->response,
            'Reward: '.$intention->reward,
        ];

        if ($previous !== null && $previous->content !== '') {
            $lines[] = '';
            $lines[] = 'Prior rolling summary:';
            $lines[] = $previous->content;
        }

        $lines[] = '';
        $lines[] = 'New events since then (oldest first):';

        foreach ($events as $event) {
            $when = $event->logged_at instanceof Carbon
                ? $event->logged_at->toDateString()
                : (string) $event->logged_at;

            $line = '- ['.$when.'] '.$event->outcome;

            if ($event->reason !== null && $event->reason !== '') {
                $line .= ' — reason: '.$event->reason;
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
