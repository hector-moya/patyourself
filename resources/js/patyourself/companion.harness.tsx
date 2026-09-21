/**
 * Rendering the clearing, with the one mistake this suite keeps making made
 * impossible.
 *
 * `companion.fixture.ts` defaults `scene: 'cabin'`, and `companion-room.tsx`
 * computes `indoors = inside || scene.name === 'cabin'` — so a test that
 * forgets `scene: 'forest'` silently renders the INTERIOR and asserts nothing
 * about the clearing at all. That has shipped five times across F3 and F3.5,
 * twice in tests written hours after someone read the warning. It is never a
 * red test; it is an assertion passing for the wrong reason.
 *
 * So this builds the payload with the scene baked in, renders, AND CHECKS
 * THE CLEARING IS ACTUALLY THERE before handing back. A test that uses it
 * cannot under-specify the scene. A test that hand-rolls its payload and
 * renders directly is not protected — nothing can protect that — but every
 * new clearing test is expected to come through here.
 *
 * Separate from `companion.fixture.ts` on purpose, twice over: that file is
 * pure data and says so, and five other suites import it without wanting the
 * whole companion page dragged in behind them.
 */
import { render, screen } from '@testing-library/react';

import CompanionPage from '@/pages/companion';
import type { CompanionBagData, CompanionData } from '@/patyourself/companion';
import { bag, companion } from '@/patyourself/companion.fixture';

export function renderClearing(
    opts: {
        companion?: Partial<CompanionData>;
        bag?: Partial<CompanionBagData>;
    } = {},
): { companion: CompanionData; bag: CompanionBagData } {
    const payload = {
        companion: companion({ scene: 'forest', ...opts.companion }),
        bag: bag(opts.bag),
    };

    render(<CompanionPage {...payload} />);

    const scene = screen.getByRole('img');

    if (
        scene.dataset.scene !== 'forest' ||
        scene.dataset.interior !== undefined
    ) {
        throw new Error(
            'the clearing did not render: got scene=' +
                `${scene.dataset.scene} interior=${scene.dataset.interior}. ` +
                "companion.fixture defaults to scene: 'cabin'.",
        );
    }

    return payload;
}
