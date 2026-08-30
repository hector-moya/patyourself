import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { StartExperimentForm } from './start-experiment-form';

describe('StartExperimentForm', () => {
    it('names the current cadence in the keep option so the choice is legible', () => {
        render(
            <StartExperimentForm loopId={1} currentCadence="daily at 19:00" />,
        );

        expect(
            screen.getByLabelText(
                /keep the current cadence \(daily at 19:00\)/i,
            ),
        ).toBeInTheDocument();
    });

    it('hides the schedule fields until the cadence is being changed', async () => {
        render(
            <StartExperimentForm loopId={1} currentCadence="daily at 19:00" />,
        );

        expect(screen.queryByLabelText(/what to do/i)).not.toBeInTheDocument();

        await userEvent.click(screen.getByLabelText(/set a new cadence/i));

        expect(screen.getByLabelText(/what to do/i)).toBeInTheDocument();
    });

    it('offers the four points of the chain and nothing else', () => {
        render(<StartExperimentForm loopId={1} currentCadence={null} />);

        const options = Array.from(
            screen
                .getByLabelText(/where in the chain/i)
                .querySelectorAll('option'),
        ).map((o) => o.getAttribute('value'));

        expect(options).toEqual(['cue', 'craving', 'response', 'reward']);
    });

    it('does not present the review window as a countdown when left empty', () => {
        render(<StartExperimentForm loopId={1} currentCadence={null} />);

        expect(
            screen.getByText(/leave this empty to run it open-ended/i),
        ).toBeInTheDocument();
    });

    /**
     * There is no cadence to name when the loop has no active action — the
     * keep option must still read cleanly, not with a dangling "()".
     */
    it('keeps the option legible with no active action to name', () => {
        render(<StartExperimentForm loopId={1} currentCadence={null} />);

        expect(
            screen.getByLabelText(/^keep the current cadence$/i),
        ).toBeInTheDocument();
    });

    /**
     * Coverage gap flagged in review: the other tests here only exercise the
     * `clock` kind on the change-cadence path. The anchored kind is a real,
     * backend-supported branch and needs its own fields to show — and the
     * clock-only fields to disappear — when it is selected.
     */
    it('shows the anchor field, and not the time/recurrence fields, for an anchored cadence', async () => {
        render(
            <StartExperimentForm loopId={1} currentCadence="daily at 19:00" />,
        );

        await userEvent.click(screen.getByLabelText(/set a new cadence/i));
        await userEvent.selectOptions(
            screen.getByLabelText(/when/i),
            'anchored',
        );

        expect(screen.getByLabelText(/after what/i)).toBeInTheDocument();
        expect(screen.queryByLabelText(/^time$/i)).not.toBeInTheDocument();
        expect(screen.queryByLabelText(/how often/i)).not.toBeInTheDocument();
    });
});
