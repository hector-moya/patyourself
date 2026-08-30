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
                $lines = [...$lines, ...$this->bulletWithPrefix('', "{$note['noted_at']} — ", (string) $note['body'])];
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
            // Without this a reader cannot tell from the prose which version
            // is the one currently running.
            '- **Status:** '.$strategy['status'],
            ...$this->bulletWithPrefix('', '**Approach:** ', (string) ($strategy['approach'] ?? '—')),
            ...$this->bulletWithPrefix('', '**Rationale:** ', (string) ($strategy['rationale'] ?? '—')),
        ];

        // An open-ended experiment is described, never counted down.
        $lines[] = '- **Review:** '.($strategy['review_at'] ?? 'open-ended');

        if ($strategy['verdict'] !== null) {
            $lines[] = "- **Verdict:** {$strategy['verdict']}";
            if ($strategy['verdict_note'] !== null) {
                $lines = [...$lines, ...$this->bulletWithPrefix('', '**In their words:** ', (string) $strategy['verdict_note'])];
            }
        }

        // The whole narrative of a lab notebook: whether this version arose
        // from a stacked success or a restrategize after a stated failure.
        if ($strategy['change_reason'] !== null) {
            $lines[] = "- **Changed because:** {$strategy['change_reason']}";
        }

        if ($strategy['superseded_reason'] !== null) {
            $lines = [...$lines, ...$this->bulletWithPrefix('', '**Superseded because:** ', (string) $strategy['superseded_reason'])];
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
        ];

        if (($action['description'] ?? null) !== null && $action['description'] !== '') {
            $lines[] = $action['description'];
            $lines[] = '';
        }

        $lines[] = '- **Recurrence:** '.($action['recurrence'] ?? 'one-off');
        $lines[] = '- **Status:** '.$action['status'];
        $lines[] = '';

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

            $lines[] = "- {$occurrence['scheduled_for']} — {$outcome['outcome']}";

            // The reason a strategy did not hold, in the user's own words.
            // Nested under its occasion — like `context` below — rather than
            // inlined onto the line above, because `reason` is a free-text
            // `<textarea>` with no line-count limit and inlining a multi-line
            // value would sever its second line into a detached paragraph.
            if ($outcome['reason'] !== null && $outcome['reason'] !== '') {
                $lines = [...$lines, ...$this->bulletWithPrefix('  ', '**Reason:** ', (string) $outcome['reason'])];
            }

            // The mechanics of what happened, in the user's own words. Indented
            // under its occasion rather than flattened into the line above, so a
            // long account stays readable. Verbatim, like the reason.
            if (($outcome['context'] ?? null) !== null && $outcome['context'] !== '') {
                $lines = [...$lines, ...$this->bulletWithPrefix('  ', '**Context:** ', (string) $outcome['context'])];
            }

            // The structured half of the same free-text record as `context` —
            // dropping it would silently discard data the JSON export carries.
            if (($outcome['context_fields'] ?? null) !== null && $outcome['context_fields'] !== []) {
                $lines[] = '  - **Also:** '.$this->contextFields($outcome['context_fields']);
            }
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function contextFields(array $fields): string
    {
        $parts = [];

        foreach ($fields as $key => $value) {
            $parts[] = "{$key}: {$value}";
        }

        return implode(', ', $parts);
    }

    /**
     * Wraps a bulleted line whose value may itself contain newlines.
     * `reason`, `context`, note bodies, and several strategy fields are all
     * free-text values with no line-count constraint, so continuation lines
     * are indented to stay inside the same Markdown list item — otherwise a
     * second line detaches into an unindented paragraph, severed from the
     * occasion or version it belongs to.
     *
     * @return array<int, string>
     */
    private function bulletWithPrefix(string $indent, string $prefix, string $value): array
    {
        $rows = explode("\n", $value);
        $lines = ["{$indent}- {$prefix}".array_shift($rows)];

        foreach ($rows as $row) {
            $lines[] = "{$indent}  {$row}";
        }

        return $lines;
    }
}
