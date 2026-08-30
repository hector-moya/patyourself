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
                    'recurrence' => 'daily', 'schedule_kind' => 'clock', 'anchor' => null,
                    'status' => 'active',
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

    public function test_it_renders_the_outcome_context_fields(): void
    {
        $markdown = (new MarkdownRecordFormatter)->render($this->record());

        // `context_fields` is the structured half of the same "complete dump"
        // as `context` — Task 1's review escalated both together.
        $this->assertStringContainsString('mood: tired', $markdown);
    }

    public function test_it_keeps_a_multi_line_reason_and_context_inside_their_list_items(): void
    {
        $record = $this->record();
        $record['loops'][0]['actions'][0]['occurrences'][0]['outcome']['reason']
            = "slept in\nand felt guilty about it";
        $record['loops'][0]['actions'][0]['occurrences'][0]['outcome']['context']
            = "alarm did not go off\nphone was on silent";

        $markdown = (new MarkdownRecordFormatter)->render($record);

        // `reason` and `context` are free-text `<textarea>` fields with no
        // line-count constraint. A continuation line must stay indented under
        // the bullet that introduces it, not detach into an unindented
        // paragraph severed from the occasion it describes.
        $this->assertStringContainsString("- **Reason:** slept in\n    and felt guilty about it", $markdown);
        $this->assertStringContainsString("- **Context:** alarm did not go off\n    phone was on silent", $markdown);
    }

    public function test_it_renders_an_actions_description(): void
    {
        $record = $this->record();
        $record['loops'][0]['actions'][0]['description'] = 'do these before coffee, not after';

        $markdown = (new MarkdownRecordFormatter)->render($record);

        $this->assertStringContainsString('do these before coffee, not after', $markdown);
    }

    public function test_it_renders_each_versions_status(): void
    {
        $markdown = (new MarkdownRecordFormatter)->render($this->record());

        // Without this a reader cannot tell from the prose which version is
        // the one currently running.
        $this->assertStringContainsString('**Status:** superseded', $markdown);
        $this->assertStringContainsString('**Status:** active', $markdown);
    }

    public function test_it_renders_why_a_version_changed(): void
    {
        $markdown = (new MarkdownRecordFormatter)->render($this->record());

        // The whole narrative of a lab notebook: whether a version arose from
        // a stacked success or a restrategize after failure.
        $this->assertStringContainsString('**Changed because:** abandoned', $markdown);
    }

    /**
     * An action is either clock-scheduled or cue-anchored; the two are
     * mutually exclusive. `recurrence` is always null for an anchored
     * action, so printing "Recurrence: one-off" for one is not a gap — it
     * is a false statement about the record.
     */
    public function test_an_anchored_action_is_never_described_as_one_off(): void
    {
        $record = $this->record();
        $record['loops'][0]['actions'][0]['recurrence'] = null;
        $record['loops'][0]['actions'][0]['schedule_kind'] = 'anchored';
        $record['loops'][0]['actions'][0]['anchor'] = 'after I close the laptop';

        $markdown = (new MarkdownRecordFormatter)->render($record);

        $this->assertStringContainsString('**When:** after I close the laptop', $markdown);
        $this->assertStringNotContainsString('one-off', $markdown);
    }

    public function test_a_clock_scheduled_action_still_reports_its_recurrence(): void
    {
        $markdown = (new MarkdownRecordFormatter)->render($this->record());

        $this->assertStringContainsString('**Recurrence:** daily', $markdown);
    }

    /**
     * `anchor` is user text typed into a free-text input (`StoreActionRequest`
     * caps it at 255 chars, but that is a length limit, not a line limit), so
     * it must go through the same continuation-safe path as `reason` and
     * `context` rather than being inlined onto the bullet.
     */
    public function test_a_multi_line_anchor_stays_inside_its_list_item(): void
    {
        $record = $this->record();
        $record['loops'][0]['actions'][0]['recurrence'] = null;
        $record['loops'][0]['actions'][0]['schedule_kind'] = 'anchored';
        $record['loops'][0]['actions'][0]['anchor'] = "after I close the laptop\nand stand up";

        $markdown = (new MarkdownRecordFormatter)->render($record);

        $this->assertStringContainsString("- **When:** after I close the laptop\n  and stand up", $markdown);
    }

    /**
     * `cue`, `craving`, `response` and `reward` are free-text `<textarea>`
     * values validated only `max:2000` (StoreIntentionRequest) — reachable
     * from the MCP `create-loop` conversation where multi-sentence values
     * are normal. A newline must not detach the remainder into a floating
     * paragraph above `## Experiments`, in the four fields that define the
     * loop.
     */
    public function test_a_multi_line_cue_stays_inside_its_list_item(): void
    {
        $record = $this->record();
        $record['loops'][0]['chain']['cue'] = "the kettle clicks off\nand the room goes quiet";

        $markdown = (new MarkdownRecordFormatter)->render($record);

        $this->assertStringContainsString("- **Cue:** the kettle clicks off\n  and the room goes quiet", $markdown);
    }
}
