import type * as InertiaReact from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { useEffect } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

const page = { url: '/dashboard', props: { unread_notifications_count: 0 } };
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

/**
 * Calls back with 42 once mounted, standing in for a workflow surface that
 * materialises an occasion of its own after the server render this screen
 * was given. Only takes over for the one sentinel workflow name a test below
 * opts into — every other workflow (including `'constructor'`, the
 * prototype-chain fallback the test suite pins elsewhere) still goes through
 * the real `WorkflowRecord`, unmodified.
 */
function MaterialisingSurface({
    onOccurrenceMaterialised,
}: {
    onOccurrenceMaterialised: (occurrenceId: number) => void;
}) {
    useEffect(() => {
        onOccurrenceMaterialised(42);
    }, [onOccurrenceMaterialised]);

    return null;
}

vi.mock('@/patyourself/workflow-record', async (importOriginal) => {
    const actual = await importOriginal<typeof WorkflowRecordModule>();

    return {
        ...actual,
        WorkflowRecord: (props: Parameters<typeof actual.WorkflowRecord>[0]) =>
            props.workflow === 'materialising-test' ? (
                <MaterialisingSurface
                    onOccurrenceMaterialised={props.onOccurrenceMaterialised}
                />
            ) : (
                <actual.WorkflowRecord {...props} />
            ),
    };
});

import { companion, noCompanion } from '@/patyourself/companion.fixture';
import type * as WorkflowRecordModule from '@/patyourself/workflow-record';

import Dashboard from './dashboard';
import type { ReadyForVerdictData, TodaysOccasionData } from './dashboard';

function occasion(
    overrides: Partial<TodaysOccasionData> = {},
): TodaysOccasionData {
    return {
        occurrence_id: 42,
        action_id: 7,
        loop_id: 3,
        loop_title: 'Evening snacking',
        workflow: null,
        title: 'Lunch without bread',
        description: null,
        due: 'due_now',
        scheduled_for: '2026-08-27T12:30:00+00:00',
        strategy: null,
        outcome: null,
        ...overrides,
    };
}

function verdict(
    overrides: Partial<ReadyForVerdictData> = {},
): ReadyForVerdictData {
    return {
        loop_id: 9,
        loop_title: 'Morning walk',
        version: 1,
        intervention_point: 'cue',
        day_of_experiment: 15,
        planned_days: 14,
        ...overrides,
    };
}

function renderDashboard(
    props: Partial<React.ComponentProps<typeof Dashboard>> = {},
) {
    return render(
        <Dashboard
            today="2026-08-27"
            now="2026-08-27T12:41:00+00:00"
            occasions={[]}
            ready_for_verdict={[]}
            companion={noCompanion()}
            {...props}
        />,
    );
}

/** Opens a collapsed slot by pressing its row. */
function openSlot(title: string) {
    fireEvent.click(screen.getByText(title));
}

// The one test below pins the wall clock (Blob's ambient reads it by
// default); this teardown runs unconditionally so a failed assertion can't
// leak a fake clock into a later test.
afterEach(() => {
    vi.useRealTimers();
});

describe('Dashboard', () => {
    it('names the day', () => {
        renderDashboard();

        expect(screen.getByText(/thursday 27 august/i)).toBeInTheDocument();
    });

    /**
     * The hour that has come and gone unanswered is the question; everything
     * else is a row on the rail. The screen opens on that one so the common
     * case — logging what just happened — takes no taps at all.
     */
    it('opens on the occasion whose hour has come', () => {
        renderDashboard({
            occasions: [
                occasion({ due: 'due_now', title: 'Lunch without bread' }),
                occasion({
                    due: 'upcoming',
                    action_id: 8,
                    occurrence_id: 43,
                    title: 'Reading',
                }),
            ],
        });

        expect(screen.getByText('Due now')).toBeInTheDocument();
        expect(screen.getByTestId('occasion-form-7')).toBeInTheDocument();
        // The later one is still a row, not a second open question.
        expect(screen.queryByTestId('occasion-form-8')).not.toBeInTheDocument();
        expect(screen.getByText('Reading')).toBeInTheDocument();
    });

    /**
     * With several hours already past, only the nearest is the thing being
     * asked. The earlier ones are still open — they say so, and stay one tap
     * away — but the screen does not ask three questions at once.
     */
    it('treats an earlier unanswered hour as still open, not as the question', () => {
        renderDashboard({
            occasions: [
                occasion({
                    action_id: 5,
                    occurrence_id: 40,
                    title: 'Breakfast pause',
                    scheduled_for: '2026-08-27T07:00:00+00:00',
                }),
                occasion({ title: 'Lunch without bread' }),
            ],
        });

        expect(
            screen.getByText(/still open · tap to record/i),
        ).toBeInTheDocument();
        expect(screen.getByTestId('occasion-form-7')).toBeInTheDocument();
        expect(screen.queryByTestId('occasion-form-5')).not.toBeInTheDocument();
    });

    /**
     * The rule is what makes the rail a day rather than a list: it says where
     * you are in it. It falls between the hour just gone and the next one due.
     */
    it('draws the now rule between what has passed and what is still ahead', () => {
        const { container } = renderDashboard({
            occasions: [
                occasion({
                    action_id: 5,
                    occurrence_id: 40,
                    scheduled_for: '2026-08-27T09:00:00+00:00',
                }),
                occasion({
                    action_id: 8,
                    occurrence_id: 43,
                    due: 'upcoming',
                    scheduled_for: '2026-08-27T21:00:00+00:00',
                }),
            ],
        });

        const rows = [...container.querySelectorAll('.t-tl > li')];

        expect(rows.map((row) => row.getAttribute('data-testid'))).toEqual([
            'occasion-5',
            'now-rule',
            'occasion-8',
        ]);
    });

    /**
     * A cue-anchored occasion has no hour, so it cannot be placed by the clock
     * and sits after everything that can. The rule still belongs in front of
     * it: "whenever the cue comes" is ahead of now, not behind it.
     */
    it('keeps the now rule ahead of occasions with no hour at all', () => {
        const { container } = renderDashboard({
            occasions: [
                occasion({
                    action_id: 5,
                    occurrence_id: 40,
                    scheduled_for: '2026-08-27T09:00:00+00:00',
                }),
                occasion({
                    action_id: 9,
                    occurrence_id: null,
                    due: 'anchored',
                    scheduled_for: null,
                }),
            ],
        });

        const rows = [...container.querySelectorAll('.t-tl > li')];

        expect(rows.map((row) => row.getAttribute('data-testid'))).toEqual([
            'occasion-5',
            'now-rule',
            'occasion-9',
        ]);
    });

    it('drops the now rule to the foot of a day with nothing left ahead', () => {
        const { container } = renderDashboard({
            occasions: [
                occasion({ scheduled_for: '2026-08-27T09:00:00+00:00' }),
            ],
        });

        const rows = [...container.querySelectorAll('.t-tl > li')];

        expect(rows.map((row) => row.getAttribute('data-testid'))).toEqual([
            'occasion-7',
            'now-rule',
        ]);
    });

    /**
     * An answered occasion stays on the rail carrying what was recorded. The
     * day is the unit here: a timeline that dropped its answered hours would
     * read as emptier the more of it you had dealt with. It states the outcome
     * and asks nothing further.
     */
    it('keeps an answered occasion on the rail without asking again', () => {
        renderDashboard({
            occasions: [occasion({ outcome: 'completed' })],
        });

        expect(screen.getByText(/logged · did it/i)).toBeInTheDocument();
        expect(screen.queryByTestId('occasion-form-7')).not.toBeInTheDocument();
    });

    it('counts both halves of the day, and only today', () => {
        renderDashboard({
            occasions: [
                occasion({ outcome: 'completed' }),
                occasion({ action_id: 8, occurrence_id: 43, title: 'Reading' }),
            ],
        });

        expect(screen.getByText('1 recorded · 1 left')).toBeInTheDocument();
    });

    /**
     * The failure this pins is silent in production: an anchored occasion has no
     * occurrence id, and posting every row to the action route would log the
     * live slot rather than the occasion on screen — with no error either way.
     */
    it('posts an occasion with a slot to the occurrence route', () => {
        renderDashboard({ occasions: [occasion({ occurrence_id: 42 })] });

        expect(screen.getByTestId('occasion-form-7')).toHaveAttribute(
            'action',
            '/occurrences/42/logs',
        );
    });

    it('posts an anchored occasion with no slot to the action route', () => {
        renderDashboard({
            occasions: [occasion({ occurrence_id: null, due: 'anchored' })],
        });

        openSlot('Lunch without bread');

        expect(screen.getByTestId('occasion-form-7')).toHaveAttribute(
            'action',
            '/actions/7/logs',
        );
    });

    /**
     * The defect this pins: `occurrence_id` is server-rendered, and a
     * workflow's recording surface can materialise an occasion on the client
     * afterwards. The occasion below starts with a null occurrence id — the
     * same anchored, not-yet-materialised case as the test above — but its
     * surface names 42 once mounted. The verdict must follow that occasion,
     * not fall back to the action route's own live slot, which can resolve
     * to a different occasion entirely from the one just recorded against.
     *
     * Named killing mutation: in `OpenSlot`, drop the `occurrenceId` state and
     * pass `logEndpoint(occasion.action_id, occasion.occurrence_id)` instead —
     * reading the stale prop straight through. This test then posts to
     * `/actions/7/logs` and fails.
     */
    it('follows a workflow surface that materialises an occasion, not the stale server prop', () => {
        renderDashboard({
            occasions: [
                occasion({
                    occurrence_id: null,
                    due: 'anchored',
                    workflow: 'materialising-test',
                }),
            ],
        });

        openSlot('Lunch without bread');

        expect(screen.getByTestId('occasion-form-7')).toHaveAttribute(
            'action',
            '/occurrences/42/logs',
        );
    });

    it('marks an anchored occasion as having no clock time', () => {
        renderDashboard({
            occasions: [
                occasion({
                    due: 'anchored',
                    occurrence_id: null,
                    scheduled_for: null,
                }),
            ],
        });

        expect(screen.getByText(/anchored/i)).toBeInTheDocument();
    });

    /**
     * Which version the occasion is testing, at the point of being asked. A
     * loop with no running experiment logs just the same, and shows no chip
     * rather than an empty one.
     */
    it('names the version an open occasion is testing', () => {
        renderDashboard({
            occasions: [occasion({ strategy: 'v2 · after breakfast' })],
        });

        expect(screen.getByText('v2 · after breakfast')).toBeInTheDocument();
    });

    it('shows no version chip for a loop with no running experiment', () => {
        const { container } = renderDashboard({
            occasions: [occasion({ strategy: null })],
        });

        expect(container.querySelector('.t-chipq')).toBeNull();
    });

    /**
     * A failed outcome carries a reason — the same rule the tool boundary
     * enforces. The chips appear only for that outcome, and the press stays
     * shut until one is picked: a failure logged without a reason is an
     * outcome nobody can learn from.
     */
    it('asks what got in the way only when the strategy did not hold', () => {
        renderDashboard({ occasions: [occasion()] });

        // Exact text throughout: the hint beside the press reads "Pick what
        // got in the way." while the chips are unanswered, so a loose match
        // here passes on the hint alone and stops proving the chips exist.
        expect(
            screen.queryByText('What got in the way?'),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /did not hold/i }));

        expect(screen.getByText('What got in the way?')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Forgot in the moment' }),
        ).toBeInTheDocument();
    });

    it('asks for no reason when the occasion never happened', () => {
        renderDashboard({ occasions: [occasion()] });

        fireEvent.click(
            screen.getByRole('button', { name: /never happened/i }),
        );

        expect(
            screen.queryByText('What got in the way?'),
        ).not.toBeInTheDocument();
    });

    it('holds the press shut until a failure has a reason', () => {
        renderDashboard({ occasions: [occasion()] });

        const press = screen.getByRole('button', { name: 'Log it' });

        expect(press).toBeDisabled();

        fireEvent.click(screen.getByRole('button', { name: /did not hold/i }));

        expect(press).toBeDisabled();

        fireEvent.click(
            screen.getByRole('button', { name: 'Forgot in the moment' }),
        );

        expect(press).toBeEnabled();
    });

    /**
     * An outcome that needs no reason is one tap from logged. The mutation this
     * kills: requiring a reason for every outcome, which would put a chip list
     * in front of "Did it".
     */
    it('opens the press as soon as a plain outcome is picked', () => {
        renderDashboard({ occasions: [occasion()] });

        fireEvent.click(screen.getByRole('button', { name: 'Did it' }));

        expect(screen.getByRole('button', { name: 'Log it' })).toBeEnabled();
    });

    /**
     * The chip and the note travel as the one `reason` the server stores.
     * Splitting them would leave the note unreadable everywhere the reason is
     * shown back — the record keeps one field, not two.
     */
    it('sends the chip and the note as a single reason', () => {
        const { container } = renderDashboard({ occasions: [occasion()] });

        fireEvent.click(screen.getByRole('button', { name: /did not hold/i }));
        fireEvent.click(screen.getByRole('button', { name: 'Too tired' }));
        fireEvent.change(screen.getByLabelText(/add a note, in your words/i), {
            target: { value: 'Back-to-back meetings' },
        });

        expect(container.querySelector('input[name="reason"]')).toHaveValue(
            'Too tired — Back-to-back meetings',
        );
    });

    it('sends the chip alone when no note is written', () => {
        const { container } = renderDashboard({ occasions: [occasion()] });

        fireEvent.click(screen.getByRole('button', { name: /did not hold/i }));
        fireEvent.click(screen.getByRole('button', { name: 'Chose not to' }));

        expect(container.querySelector('input[name="reason"]')).toHaveValue(
            'Chose not to',
        );
    });

    /**
     * A version past its review date is not late. The section states that a
     * decision is available and carries no count and no alarm language.
     */
    it('offers a verdict without counting or alarming', () => {
        renderDashboard({ ready_for_verdict: [verdict()] });

        expect(screen.getByText(/ready for a verdict/i)).toBeInTheDocument();
        expect(screen.getByText(/morning walk/i)).toBeInTheDocument();
        expect(
            screen.queryByText(/overdue|late|behind|\(1\)/i),
        ).not.toBeInTheDocument();
    });

    /**
     * The dashboard used to state the review with no way to act on it. Each
     * row now leads to the loop's own record, where the verdict form lives.
     */
    it('links a review-due experiment to the record where it can be answered', () => {
        renderDashboard({ ready_for_verdict: [verdict({ loop_id: 1 })] });

        const link = screen.getByRole('link', { name: /give it a verdict/i });

        expect(link.getAttribute('href')).toContain('/loops/1');
    });

    it('omits the verdict section when nothing is ready', () => {
        renderDashboard({ occasions: [occasion()] });

        expect(
            screen.queryByText(/ready for a verdict/i),
        ).not.toBeInTheDocument();
    });

    /**
     * Blob rides in the corner and links to its own screen. It carries no
     * count and no badge: the header is not where progress gets tallied.
     */
    it('puts Blob in the corner once it exists', () => {
        renderDashboard({
            companion: noCompanion({
                stage_index: 1,
                log_count: 1,
                features: ['blob'],
            }),
        });

        const link = screen.getByRole('link', { name: 'Blob' });

        expect(link).toHaveAttribute('href', '/companion');
    });

    it('shows no corner at all before Blob exists', () => {
        renderDashboard();

        expect(screen.queryByRole('link', { name: 'Blob' })).toBeNull();
    });

    /**
     * A day with nothing scheduled is a normal day. The empty state says so and
     * offers nothing to catch up on — and there is no tally either, because
     * "0 recorded · 0 left" is a score for a day that was never played.
     */
    it('states an empty day as a fact, not a backlog', () => {
        renderDashboard();

        expect(screen.getByText(/nothing due today/i)).toBeInTheDocument();
        expect(
            screen.queryByText(/behind|missed|overdue|catch up/i),
        ).not.toBeInTheDocument();
        expect(screen.queryByText(/recorded ·/i)).not.toBeInTheDocument();
    });

    /** A day fully answered says so once, and asks for nothing further. */
    it('closes a day where everything has an answer', () => {
        renderDashboard({ occasions: [occasion({ outcome: 'completed' })] });

        expect(
            screen.getByText(/everything today has an answer/i),
        ).toBeInTheDocument();
    });

    /**
     * The corner is the only place the reaction lands. Nothing else on this
     * screen changes when an outcome is recorded — no copy, no toast, no line.
     */
    it('hands the just-recorded outcome to the corner Blob', () => {
        const { container } = renderDashboard({
            companion: companion(),
            logged_outcome_id: 101,
        });

        expect(
            container
                .querySelector('.blob-anim')
                ?.getAttribute('data-animation'),
        ).toBe('notice');
    });

    /**
     * Pinned to daytime: `ambientFor` defaults to the wall clock, and the
     * fixture's room reads as asleep outside it — the assertion below would
     * otherwise go red for anyone running the suite at night.
     */
    it('leaves Blob at rest on a plain visit', () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-09-11T13:00:00'));

        const { container } = renderDashboard({
            companion: companion(),
            logged_outcome_id: null,
        });

        expect(
            container
                .querySelector('.blob-anim')
                ?.getAttribute('data-animation'),
        ).toBe('idle');
    });

    /**
     * The dashboard passes no `registry` prop, so this exercises the fallback
     * against the real shipped WORKFLOWS object rather than a test double.
     * `'constructor'` is the prototype-chain trap: a plain `registry[name]`
     * lookup (instead of `Object.hasOwn`) resolves it to `Object`'s own
     * constructor rather than `undefined`, which is truthy and would slip
     * past a `??` fallback — an ordinary absent key like `'gimnasio'` cannot
     * tell that mutation apart from the correct behaviour.
     *
     * Two independent guards now stand between that mutation and a blank
     * screen: `workflowFor`'s own fallback (pinned on its own, against this
     * same shipped registry, in workflows.test.ts) and the error boundary
     * `WorkflowRecord` wraps its surface in. Reverting either guard alone
     * still leaves this test green; only reverting both at once turns it red,
     * because reverting the registry guard alone hands the boundary nothing
     * to catch, and removing the boundary alone leaves a registry that never
     * mis-resolves in the first place. That is the point of layering them —
     * this test is the outermost proof that the pair holds together.
     */
    it('renders the plain verdict controls for a loop whose workflow is an inherited property name', () => {
        renderDashboard({
            occasions: [occasion({ workflow: 'constructor' })],
        });

        expect(
            screen.getByRole('button', { name: 'Did it' }),
        ).toBeInTheDocument();
    });
});
