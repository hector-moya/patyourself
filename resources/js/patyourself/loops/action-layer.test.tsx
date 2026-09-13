import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { ActionLayer } from './action-layer';

const actions = [{ id: 3, title: 'Weigh in', cadence: 'daily at 07:00' }];

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

    /**
     * The add form mounts only once the disclosure is opened — see
     * ActionLayer's comment on why — so this opens it first rather than
     * asserting on the collapsed markup.
     */
    it('posts a new action to the loop it belongs to', async () => {
        const { container } = render(
            <ActionLayer loopId={2} actions={actions} />,
        );

        await userEvent.click(screen.getByText(/add an action/i));

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
                actions={[{ id: 4, title: 'Stretch', cadence: null }]}
            />,
        );

        const title = screen.getByText('Stretch');
        expect(title.parentElement?.children).toHaveLength(1);
    });
});
