import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { IntentionData } from '@/patyourself/types';

import { Anatomy } from './anatomy';

function intention(overrides: Partial<IntentionData> = {}): IntentionData {
    return {
        id: 1,
        title: 'Read before bed',
        type: 'build',
        status: 'active',
        workflow: null,
        cue: 'Phone on the charger',
        craving: 'Wind down',
        response: 'Read ten pages',
        reward: 'Calmer sleep',
        description: null,
        metadata: null,
        created_at: null,
        updated_at: null,
        strategy: null,
        active_action: null,
        ...overrides,
    };
}

describe('Anatomy', () => {
    /**
     * The chain is the loop's identity, so it must survive the collapse. The
     * four stage words are the summary; the stage content is not.
     *
     * Killing mutation: render the summary as the literal text "Habit anatomy".
     * The four assertions below fail — the identity would be gone from the
     * collapsed state, which is the whole reason the line exists.
     */
    it('names the four stages in the collapsed line', () => {
        render(<Anatomy intention={intention()} interventionPoint="cue" />);

        const chain = screen.getByTestId('loop-chain');

        expect(chain).toHaveTextContent(/cue/i);
        expect(chain).toHaveTextContent(/craving/i);
        expect(chain).toHaveTextContent(/response/i);
        expect(chain).toHaveTextContent(/reward/i);
    });

    /**
     * Where the strategy acts is the one fact the anatomy carries that
     * actually changes, so it is the one fact that must not need a tap.
     *
     * Killing mutation: drop the `data-acting` attribute, or set it on every
     * stage. Either way exactly-one-marked fails.
     */
    it('marks the acting stage in the collapsed line, and only that one', () => {
        const { container } = render(
            <Anatomy intention={intention()} interventionPoint="response" />,
        );

        const marked = container.querySelectorAll('[data-acting="true"]');

        expect(marked).toHaveLength(1);
        expect(marked[0]).toHaveTextContent(/response/i);
    });

    /**
     * A loop between experiments has no intervention point. Marking a stage
     * anyway would claim a strategy acts somewhere it does not.
     */
    it('marks nothing when there is no intervention point', () => {
        const { container } = render(
            <Anatomy intention={intention()} interventionPoint={null} />,
        );

        expect(container.querySelectorAll('[data-acting="true"]')).toHaveLength(
            0,
        );
    });

    /**
     * Collapsed is the default: the anatomy costs about a screen and a half
     * and changes perhaps twice in a loop's life.
     *
     * Note `<details>` keeps its content in the DOM either way, so this
     * asserts the element's own open state rather than the content's absence.
     */
    it('is collapsed by default', () => {
        render(<Anatomy intention={intention()} interventionPoint="cue" />);

        expect(screen.getByTestId('habit-anatomy')).not.toHaveAttribute('open');
    });

    it('carries every stage’s wording once disclosed', () => {
        render(<Anatomy intention={intention()} interventionPoint="cue" />);

        expect(
            screen.getByText(/phone on the charger/i),
        ).toBeInTheDocument();
        expect(screen.getByText(/wind down/i)).toBeInTheDocument();
        expect(screen.getByText(/read ten pages/i)).toBeInTheDocument();
        expect(screen.getByText(/calmer sleep/i)).toBeInTheDocument();
    });
});
