<?php

namespace Tests\Unit\Export;

use App\Services\Export\MarkdownRecordFormatter;
use Tests\TestCase;

class MarkdownRecordFormatterTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function record(): array
    {
        return [
            'exported_at' => '2026-08-30T09:00:00+00:00',
            'user' => ['name' => 'Hector', 'email' => 'h@example.com', 'timezone' => 'Europe/London'],
            'loops' => [[
                'title' => 'morning press-ups',
                'description' => null,
                'type' => 'build',
                'status' => 'active',
                'chain' => [
                    'cue' => 'the kettle clicks off',
                    'craving' => 'something to do with my hands',
                    'response' => 'ten press-ups',
                    'reward' => 'the coffee tastes earned',
                ],
                'created_at' => '2026-08-01T07:00:00+00:00',
                'strategies' => [
                    [
                        'version' => 1, 'status' => 'superseded',
                        'intervention_point' => 'cue', 'approach' => 'put the mat by the kettle',
                        'rationale' => 'the cue was invisible', 'change_reason' => 'abandoned',
                        'superseded_reason' => 'the kettle is not reliable at weekends',
                        'review_at' => null, 'verdict' => 'abandoned',
                        'verdict_note' => 'the kettle is not a reliable cue on weekends',
                        'created_at' => '2026-08-01T07:00:00+00:00',
                    ],
                    [
                        'version' => 2, 'status' => 'active',
                        'intervention_point' => 'response', 'approach' => 'two press-ups, not ten',
                        'rationale' => 'ten was the barrier', 'change_reason' => null,
                        'superseded_reason' => null, 'review_at' => null,
                        'verdict' => null, 'verdict_note' => null,
                        'created_at' => '2026-08-15T07:00:00+00:00',
                    ],
                ],
                'actions' => [[
                    'title' => 'press-ups', 'description' => null,
                    'recurrence' => 'daily', 'status' => 'active',
                    'series_started_at' => '2026-08-15T07:00:00+00:00',
                    'occurrences' => [[
                        'scheduled_for' => '2026-08-16T07:00:00+00:00',
                        'fired_at' => '2026-08-16T07:00:00+00:00',
                        'outcome' => [
                            'outcome' => 'failed',
                            'reason' => '  slept through it  ',
                            'context' => 'alarm went off, I turned it off in my sleep',
                            'context_fields' => ['mood' => 'tired'],
                            'logged_at' => '2026-08-16T20:00:00+00:00',
                        ],
                    ]],
                ]],
                'notes' => [['body' => 'easier on days I sleep well', 'noted_at' => '2026-08-17T09:00:00+00:00']],
                'reflections' => [[
                    'scope' => 'loop', 'content' => 'the cue is the problem, not the response',
                    'window_start' => null, 'window_end' => null,
                    'events_count' => 12, 'created_at' => '2026-08-20T09:00:00+00:00',
                ]],
            ]],
        ];
    }

    public function test_it_renders_a_loop_with_two_versions_and_a_verdict(): void
    {
        $markdown = (new MarkdownRecordFormatter)->render($this->record());

        $this->assertStringContainsString('# morning press-ups', $markdown);
        $this->assertStringContainsString('the kettle clicks off', $markdown);
        $this->assertStringContainsString('Version 1', $markdown);
        $this->assertStringContainsString('Version 2', $markdown);
        $this->assertStringContainsString('abandoned', $markdown);
        $this->assertStringContainsString('the kettle is not a reliable cue on weekends', $markdown);
    }

    public function test_it_carries_a_failure_reason_verbatim(): void
    {
        $markdown = (new MarkdownRecordFormatter)->render($this->record());

        // The stored text had surrounding whitespace and keeps it.
        $this->assertStringContainsString('  slept through it  ', $markdown);
    }

    public function test_it_carries_the_outcome_context(): void
    {
        $markdown = (new MarkdownRecordFormatter)->render($this->record());

        // `context` is what LogOutcomeTool calls "the primary record" — the
        // mechanics of what happened. Prose that drops it is not the notebook.
        $this->assertStringContainsString(
            'alarm went off, I turned it off in my sleep',
            $markdown,
        );
    }

    public function test_it_never_scores_the_record(): void
    {
        $markdown = strtolower((new MarkdownRecordFormatter)->render($this->record()));

        // No gamification: the notebook reports what happened, it does not grade it.
        foreach (['streak', 'completion rate', '% complete', 'score', 'well done', 'great job'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $markdown);
        }
    }

    public function test_an_empty_record_renders_a_valid_document(): void
    {
        $markdown = (new MarkdownRecordFormatter)->render([
            'exported_at' => '2026-08-30T09:00:00+00:00',
            'user' => ['name' => 'Hector', 'email' => 'h@example.com', 'timezone' => null],
            'loops' => [],
        ]);

        $this->assertStringContainsString('PatYourSelf', $markdown);
        $this->assertStringContainsString('No loops', $markdown);
    }
}
