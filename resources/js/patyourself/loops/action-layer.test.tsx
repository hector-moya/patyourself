import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import type {
    WorkflowConfigProps,
    WorkflowRegistry,
} from '@/patyourself/workflows';
import type { ActionSummary } from './action-layer';
import { ActionLayer } from './action-layer';

const actions = [
    {
        id: 3,
        title: 'Weigh in',
        cadence: 'daily at 07:00',
        scheduleKind: null,
        time: null,
        recurrence: null,
        anchor: null,
        date: null,
        routine: null,
    },
];

describe('ActionLayer', () => {
    it('lists the loop’s live actions with their cadence', () => {
        render(<ActionLayer loopId={2} actions={actions} />);

        expect(screen.getByText('Weigh in')).toBeInTheDocument();
        expect(screen.getByText('daily at 07:00')).toBeInTheDocument();
    });

    it('says retire, never delete, and says the history is kept', () => {
        render(<ActionLayer loopId={2} actions={actions} />);

        expect(
            screen.getByRole('button', { name: /retire/i }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /delete/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(/everything it recorded is kept/i),
        ).toBeInTheDocument();
    });

    it('posts a new action to the loop it belongs to', () => {
        const { container } = render(
            <ActionLayer loopId={2} actions={actions} />,
        );

        const addForm = container.querySelector(
            'form[action*="/loops/2/actions"]',
        );
        expect(addForm).not.toBeNull();
    });

    /**
     * The defect this codebase already fixed once, for the active-action
     * cadence label: a recurrence with no time left to report must not render
     * as a dangling "daily at " — see cadenceLabel. A null cadence omits the
     * line entirely rather than rendering an empty or partial string.
     */
    it('renders no dangling cadence when there is nothing to name', () => {
        render(
            <ActionLayer
                loopId={2}
                actions={[
                    {
                        id: 4,
                        title: 'Stretch',
                        cadence: null,
                        scheduleKind: null,
                        time: null,
                        recurrence: null,
                        anchor: null,
                        date: null,
                        routine: null,
                    },
                ]}
            />,
        );

        const title = screen.getByText('Stretch');
        expect(title.parentElement?.children).toHaveLength(1);
    });
});

/**
 * Stands in for a module's configuration surface. The real RoutineEditor is
 * never rendered here, for the reason workflow-config.test.tsx gives: the
 * action layer's job is to decide the row's shape, not to draw gym.
 */
function FakeRoutine({ actionId, rows }: WorkflowConfigProps) {
    return (
        <div data-testid={`fake-routine-${actionId}`}>
            {rows.length === 0 ? (
                <p>No exercises on this one yet.</p>
            ) : (
                rows.map((row) => <p key={row.id}>{row.exercise_name}</p>)
            )}
            <p>Add an exercise</p>
        </div>
    );
}

const CONFIGURING: WorkflowRegistry = {
    fake: { name: 'fake', label: 'Fake', config: FakeRoutine, record: null },
};

// `time` and `date` both come off the same `series_started_at`, so a fixture
// with one and not the other describes a state the server cannot send — and it
// is this fixture that the only in-disclosure editor test opens on.
const pullDay = {
    id: 7,
    title: 'Pull day',
    cadence: 'weekly at 07:30',
    scheduleKind: 'clock' as const,
    time: '07:30',
    recurrence: 'weekly',
    anchor: null,
    date: '2026-09-23',
    routine: [
        {
            id: 11,
            exercise_id: 2,
            exercise_name: 'Barbell Row',
            position: 1,
            target_sets: 3,
            target_reps: 10,
        },
    ],
};

const pushDay = {
    ...pullDay,
    id: 8,
    title: 'Push day',
    routine: [
        {
            id: 12,
            exercise_id: 3,
            exercise_name: 'Overhead Press',
            position: 1,
            target_sets: 3,
            target_reps: 8,
        },
    ],
};

describe('ActionLayer disclosure', () => {
    it('collapses an action that has a configuration surface', () => {
        render(
            <ActionLayer
                loopId={2}
                actions={[pullDay]}
                workflow="fake"
                registry={CONFIGURING}
            />,
        );

        expect(screen.getByText('Pull day')).toBeVisible();
        expect(screen.getByText('weekly at 07:30')).toBeVisible();
        expect(screen.getByText('Barbell Row')).not.toBeVisible();
        expect(
            screen.getByRole('button', { name: /edit pull day/i }),
        ).not.toBeVisible();
        expect(
            screen.getByRole('button', { name: /retire/i }),
        ).not.toBeVisible();
    });

    it('reveals the routine and the action’s own controls when opened', async () => {
        const user = userEvent.setup();

        render(
            <ActionLayer
                loopId={2}
                actions={[pullDay]}
                workflow="fake"
                registry={CONFIGURING}
            />,
        );

        await user.click(screen.getByText('Pull day'));

        expect(screen.getByText('Barbell Row')).toBeVisible();
        expect(screen.getByText('Add an exercise')).toBeVisible();
        expect(
            screen.getByRole('button', { name: /edit pull day/i }),
        ).toBeVisible();
        expect(screen.getByRole('button', { name: /retire/i })).toBeVisible();
    });

    // Plain <details>, no `name` grouping and no shared state: comparing two
    // sessions' routines is an ordinary thing to want.
    it('leaves one action open when another is opened', async () => {
        const user = userEvent.setup();

        render(
            <ActionLayer
                loopId={2}
                actions={[pullDay, pushDay]}
                workflow="fake"
                registry={CONFIGURING}
            />,
        );

        await user.click(screen.getByText('Pull day'));
        await user.click(screen.getByText('Push day'));

        expect(screen.getByText('Barbell Row')).toBeVisible();
        expect(screen.getByText('Overhead Press')).toBeVisible();
    });

    // An empty routine is still a routine — rows: [] is not rows: null.
    it('collapses an action whose routine is empty', async () => {
        const user = userEvent.setup();

        render(
            <ActionLayer
                loopId={2}
                actions={[{ ...pullDay, routine: [] }]}
                workflow="fake"
                registry={CONFIGURING}
            />,
        );

        expect(
            screen.getByText('No exercises on this one yet.'),
        ).not.toBeVisible();

        await user.click(screen.getByText('Pull day'));

        expect(
            screen.getByText('No exercises on this one yet.'),
        ).toBeVisible();
    });

    /**
     * The client-side sibling of PlainLoopIsUnchangedTest, and the regression
     * that matters most here: a loop with no workflow must not grow a
     * disclosure with nothing behind it.
     */
    it('leaves a plain loop’s action flat, with its controls reachable', () => {
        const { container } = render(
            <ActionLayer
                loopId={2}
                actions={[{ ...pullDay, routine: null }]}
                registry={CONFIGURING}
            />,
        );

        expect(container.querySelector('li > details')).toBeNull();
        expect(
            screen.getByRole('button', { name: /edit pull day/i }),
        ).toBeVisible();
        expect(screen.getByRole('button', { name: /retire/i })).toBeVisible();
    });

    /**
     * A workflow registered on the server and absent from the client sends
     * rows while the client registry resolves nothing. docs/WORKFLOWS.md §11
     * records that state as "configurable and draws nothing"; it must not
     * become "configurable, draws nothing, and hides the controls".
     */
    it('leaves an action flat when the client registry does not know the loop’s workflow', () => {
        const { container } = render(
            <ActionLayer
                loopId={2}
                actions={[pullDay]}
                workflow="journal"
                registry={CONFIGURING}
            />,
        );

        expect(container.querySelector('li > details')).toBeNull();
        expect(
            screen.getByRole('button', { name: /edit pull day/i }),
        ).toBeVisible();
    });

    /**
     * The seam between this branch's two changes: the editor opened inside a
     * disclosure must be the same editor as the one opened on a flat row,
     * pre-filled from the action's own anchor. An empty date here drops the
     * owner's chosen start date, so a later save that does change the
     * schedule would re-derive the anchor from now instead of honouring the
     * date they picked.
     */
    it('opens the editor inside the body and leaves the disclosure open', async () => {
        const user = userEvent.setup();

        render(
            <ActionLayer
                loopId={2}
                actions={[pullDay]}
                workflow="fake"
                registry={CONFIGURING}
            />,
        );

        await user.click(screen.getByText('Pull day'));
        await user.click(screen.getByRole('button', { name: /edit pull day/i }));

        expect(screen.getByTestId('action-editor-7')).toBeVisible();
        expect(screen.getByText('Barbell Row')).toBeVisible();

        const date = screen.getByLabelText('Starts on');
        expect(date).toBeVisible();
        expect(date).toHaveValue('2026-09-23');
    });
});

// Shared by 'ActionEditor start date' and 'ActionEditor longer cadences'
// below: one fixture and one way to reach the editor, so a cadence added to
// one suite cannot describe a different action shape from the other.
const weekly: ActionSummary = {
    id: 3,
    title: 'Weigh in',
    cadence: 'weekly at 07:30',
    scheduleKind: 'clock',
    time: '07:30',
    recurrence: 'weekly',
    anchor: null,
    date: '2026-09-23',
    routine: null,
};

async function openEditor(action: typeof weekly) {
    const user = userEvent.setup();
    render(<ActionLayer loopId={2} actions={[action]} />);
    await user.click(
        screen.getByRole('button', { name: `Edit ${action.title}` }),
    );

    return user;
}

describe('ActionEditor start date', () => {
    it('asks for a start date on a weekly action, pre-filled from its anchor', async () => {
        await openEditor(weekly);

        const date = screen.getByLabelText('Starts on');

        expect(date).toHaveAttribute('name', 'date');
        expect(date).toHaveAttribute('type', 'date');
        expect(date).toHaveValue('2026-09-23');
    });

    it('asks for a start date on a one-off, whose date is the event', async () => {
        await openEditor({ ...weekly, recurrence: null, cadence: null });

        expect(screen.getByLabelText('Starts on')).toBeInTheDocument();
    });

    // "Every day at 07:00" — a date names nothing, so the field is not asked
    // for, and a save carrying no date takes the derived-anchor path.
    it('asks for no start date on a daily action', async () => {
        await openEditor({ ...weekly, recurrence: 'daily' });

        expect(screen.queryByLabelText('Starts on')).not.toBeInTheDocument();
    });

    it('asks for no start date on a weekdays action', async () => {
        await openEditor({ ...weekly, recurrence: 'weekdays' });

        expect(screen.queryByLabelText('Starts on')).not.toBeInTheDocument();
    });

    /**
     * Unmounting the input is the mechanism, not a side effect of one: with no
     * date in the payload the server derives the anchor exactly as it always
     * has.
     */
    it('removes the start date from the form when the recurrence stops needing one', async () => {
        await openEditor(weekly);
        // Scoped: the always-present "Add an action" form below has its own
        // "How often" field, unrelated to this action's editor. `fireEvent`
        // rather than `userEvent.selectOptions`: the latter dispatches an
        // `input` event ahead of `change`, and *any* such event races Inertia's
        // own dirty-tracking listener, which snaps the select back to its
        // defaults before `change` fires. `change` then recomputes dirtiness
        // against a form that has just been reset, so it finds nothing changed
        // and the select never escapes — repeating the interaction does not
        // help, because every repeat starts from a pristine form again. An
        // artifact of `act()` flushing a deferred transition mid-interaction,
        // not a production bug.
        const editor = within(screen.getByTestId(`action-editor-${weekly.id}`));

        fireEvent.change(editor.getByLabelText('How often'), {
            target: { value: 'daily' },
        });

        expect(screen.queryByLabelText('Starts on')).not.toBeInTheDocument();
    });

    it('asks for it again when the recurrence needs one once more', async () => {
        await openEditor(weekly);
        // `fireEvent` for the reason the test above spells out — the two must
        // be modernised together or not at all.
        const editor = within(screen.getByTestId(`action-editor-${weekly.id}`));

        fireEvent.change(editor.getByLabelText('How often'), {
            target: { value: 'daily' },
        });
        fireEvent.change(editor.getByLabelText('How often'), {
            target: { value: 'weekly' },
        });

        expect(screen.getByLabelText('Starts on')).toBeInTheDocument();
    });

    /**
     * An Inertia <Form> renders a real <form>, so native constraint validation
     * runs on submit. A `min` of today against a pre-filled past date would
     * block the pure rename this editor exists to allow — the rule is the
     * server's, and the server snaps a past date forward rather than refusing
     * it.
     */
    it('puts no minimum on the date input', async () => {
        await openEditor({ ...weekly, date: '2026-09-09' });

        expect(screen.getByLabelText('Starts on')).not.toHaveAttribute('min');
    });

    it('asks for no start date on a cue-anchored action', async () => {
        await openEditor({
            ...weekly,
            scheduleKind: 'anchored' as const,
            time: null,
            recurrence: null,
            anchor: 'after work',
            date: null,
        });

        expect(screen.queryByLabelText('Starts on')).not.toBeInTheDocument();
    });
});

describe('ActionEditor longer cadences', () => {
    it.each(['fortnightly', 'monthly'])(
        'asks for a start date on a %s action, which names a day',
        async (recurrence) => {
            await openEditor({ ...weekly, recurrence });

            expect(screen.getByLabelText('Starts on')).toBeInTheDocument();
        },
    );

    it('offers every cadence, longest commitment last', async () => {
        await openEditor(weekly);

        // Scoped for the same reason the tests above are: the always-present
        // "Add an action" form has its own "How often" select.
        const editor = within(screen.getByTestId(`action-editor-${weekly.id}`));
        const select = editor.getByLabelText('How often');
        const offered = Array.from(
            select.querySelectorAll('option'),
        ).map((option) => option.value);

        expect(offered).toEqual([
            'once',
            'daily',
            'weekdays',
            'weekly',
            'fortnightly',
            'monthly',
        ]);
    });

    it('switches from monthly to daily and drops the start date', async () => {
        await openEditor({ ...weekly, recurrence: 'monthly' });

        expect(screen.getByLabelText('Starts on')).toBeInTheDocument();

        // Scoped, and `fireEvent` rather than `userEvent.selectOptions`, for
        // the reason recorded above in 'ActionEditor start date'.
        const editor = within(screen.getByTestId(`action-editor-${weekly.id}`));

        fireEvent.change(editor.getByLabelText('How often'), {
            target: { value: 'daily' },
        });

        expect(screen.queryByLabelText('Starts on')).not.toBeInTheDocument();
    });
});
