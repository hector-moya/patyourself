import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { ReflectionData } from '@/patyourself/types';
import { Reflection } from './reflection';

function reflection(overrides: Partial<ReflectionData> = {}): ReflectionData {
    return {
        content: 'The craving reads more like hunger than habit.',
        window_start: '2026-08-13T00:00:00+00:00',
        window_end: '2026-08-27T00:00:00+00:00',
        events_count: 28,
        ...overrides,
    };
}

describe('Reflection', () => {
    /**
     * Reflections are verbatim. The fixture arrives untidy on purpose — a tidy
     * input would pass against an implementation that trims, squishes or
     * sentence-cases, and would prove nothing.
     */
    it('renders the body exactly as it was written', () => {
        const content =
            '  three of the last five breaks came after a SKIPPED lunch.\n\n  the craving reads more like hunger.  ';

        render(<Reflection reflection={reflection({ content })} />);

        expect(screen.getByTestId('reflection-body')).toHaveTextContent(
            content,
            { normalizeWhitespace: false },
        );
    });

    it('renders the window and the occasion count it covers', () => {
        render(<Reflection reflection={reflection({ events_count: 28 })} />);

        const provenance = screen.getByTestId('reflection-provenance');

        expect(provenance).toHaveTextContent(/28 occasions/i);
        expect(provenance).toHaveTextContent(/Aug/);
    });

    it('says one occasion in the singular', () => {
        render(<Reflection reflection={reflection({ events_count: 1 })} />);

        expect(screen.getByTestId('reflection-provenance')).toHaveTextContent(
            /1 occasion(?!s)/i,
        );
    });

    /**
     * A reflection written before the window was recorded still has words worth
     * reading. It renders without a stray separator or an empty date range.
     */
    it('omits the provenance line when the window is unknown', () => {
        render(
            <Reflection
                reflection={reflection({
                    window_start: null,
                    window_end: null,
                    events_count: null,
                })}
            />,
        );

        expect(
            screen.getByText(/hunger than habit/i),
        ).toBeInTheDocument();
        expect(
            screen.queryByTestId('reflection-provenance'),
        ).not.toBeInTheDocument();
    });

    /**
     * There is no coach in the app any more — the words come from Claude through
     * the MCP connector. The empty state states a fact rather than implying
     * something is outstanding, because the notebook never nags.
     */
    it('states the empty case without a coach and without implying a debt', () => {
        render(<Reflection reflection={null} />);

        expect(
            screen.getByText(/no reflection written yet/i),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/coach|hasn't|has not|yet to|waiting|overdue/i),
        ).not.toBeInTheDocument();
    });

    it('heads the section with what the record shows', () => {
        render(<Reflection reflection={reflection()} />);

        expect(screen.getByText(/what the record shows/i)).toBeInTheDocument();
        expect(screen.queryByText(/coach summary/i)).not.toBeInTheDocument();
    });
});
