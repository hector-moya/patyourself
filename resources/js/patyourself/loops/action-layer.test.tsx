import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import type {
    WorkflowConfigProps,
    WorkflowRegistry,
} from '@/patyourself/workflows';
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

const pullDay = {
    id: 7,
    title: 'Pull day',
    cadence: 'weekly at 07:30',
    scheduleKind: 'clock' as const,
    time: '07:30',
    recurrence: 'weekly',
    anchor: null,
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
    });
});
