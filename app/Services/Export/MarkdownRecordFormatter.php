<?php

namespace App\Services\Export;

/**
 * The record as prose — the lab notebook you would actually read back.
 *
 * It reports and never grades: no streaks, no completion rates, no scores.
 * An experiment with no planned length is described as open-ended rather than
 * as a countdown, and the user's own words are reproduced exactly, including
 * whatever whitespace they typed.
 */
final readonly class MarkdownRecordFormatter
{
    /**
     * @param  array<string, mixed>  $record
     */
    public function render(array $record): string
    {
        $lines = [
            '# PatYourSelf — the record',
            '',
            "Exported {$record['exported_at']} for {$record['user']['name']} <{$record['user']['email']}>.",
            '',
        ];

        if ($record['loops'] === []) {
            $lines[] = 'No loops yet.';
            $lines[] = '';

            return implode("\n", $lines);
        }

        foreach ($record['loops'] as $loop) {
            $lines = [...$lines, ...$this->loop($loop)];
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $loop
     * @return array<int, string>
     */
    private function loop(array $loop): array
    {
        $lines = [
            "# {$loop['title']}",
            '',
            "{$loop['type']} · {$loop['status']}",
            '',
        ];

        if ($loop['description'] !== null) {
            $lines[] = $loop['description'];
            $lines[] = '';
        }

        $lines[] = '## The chain';
        $lines[] = '';
        foreach (['cue' => 'Cue', 'craving' => 'Craving', 'response' => 'Response', 'reward' => 'Reward'] as $key => $label) {
            $lines[] = "- **{$label}:** ".($loop['chain'][$key] ?? '—');
        }
        $lines[] = '';

        if ($loop['strategies'] !== []) {
            $lines[] = '## Experiments';
            $lines[] = '';
            foreach ($loop['strategies'] as $strategy) {
                $lines = [...$lines, ...$this->strategy($strategy)];
            }
        }

        foreach ($loop['actions'] as $action) {
            $lines = [...$lines, ...$this->action($action)];
        }

        if ($loop['notes'] !== []) {
            $lines[] = '## Notes';
            $lines[] = '';
            foreach ($loop['notes'] as $note) {
                $lines[] = "- {$note['noted_at']} — {$note['body']}";
            }
            $lines[] = '';
        }

        if ($loop['reflections'] !== []) {
            $lines[] = '## Reflections';
            $lines[] = '';
            foreach ($loop['reflections'] as $reflection) {
                $lines[] = "### {$reflection['created_at']}";
                $lines[] = '';
                $lines[] = (string) $reflection['content'];
                $lines[] = '';
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $strategy
     * @return array<int, string>
     */
    private function strategy(array $strategy): array
    {
        $lines = [
            "### Version {$strategy['version']} — intervening at ".($strategy['intervention_point'] ?? 'an unrecorded point'),
            '',
            '- **Approach:** '.($strategy['approach'] ?? '—'),
            '- **Rationale:** '.($strategy['rationale'] ?? '—'),
        ];

        // An open-ended experiment is described, never counted down.
        $lines[] = '- **Review:** '.($strategy['review_at'] ?? 'open-ended');

        if ($strategy['verdict'] !== null) {
            $lines[] = "- **Verdict:** {$strategy['verdict']}";
            if ($strategy['verdict_note'] !== null) {
                $lines[] = "- **In their words:** {$strategy['verdict_note']}";
            }
        }

        if ($strategy['superseded_reason'] !== null) {
            $lines[] = "- **Superseded because:** {$strategy['superseded_reason']}";
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<int, string>
     */
    private function action(array $action): array
    {
        $lines = [
            "## Action: {$action['title']}",
            '',
            '- **Recurrence:** '.($action['recurrence'] ?? 'one-off'),
            '- **Status:** '.$action['status'],
            '',
        ];

        if ($action['occurrences'] === []) {
            return $lines;
        }

        $lines[] = '### What happened';
        $lines[] = '';

        foreach ($action['occurrences'] as $occurrence) {
            $outcome = $occurrence['outcome'];

            if ($outcome === null) {
                $lines[] = "- {$occurrence['scheduled_for']} — not logged";

                continue;
            }

            $line = "- {$occurrence['scheduled_for']} — {$outcome['outcome']}";

            if ($outcome['reason'] !== null && $outcome['reason'] !== '') {
                $line .= " — {$outcome['reason']}";
            }

            $lines[] = $line;

            // The mechanics of what happened, in the user's own words. Indented
            // under its occasion rather than flattened into the line above, so a
            // long account stays readable. Verbatim, like the reason.
            if (($outcome['context'] ?? null) !== null && $outcome['context'] !== '') {
                $lines[] = "  - {$outcome['context']}";
            }
        }

        $lines[] = '';

        return $lines;
    }
}
