import type * as InertiaReact from '@inertiajs/react';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const page = { url: '/companion', props: {} };
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import { bag } from '@/patyourself/companion.fixture';

import { CompanionBag } from './companion-bag';

/**
 * The bag, which is the surface most at risk of becoming a checklist: it is the
 * only place in the feature that shows something you do not have yet.
 *
 * The rule it keeps is the one the whole arc turns on — no total, no completion
 * percentage, no end state. Show what is choosable now.
 */
describe('the bag', () => {
    const open = (data = bag()) =>
        render(
            <CompanionBag bag={data} open onOpenChange={() => undefined} />,
        );

    /**
     * The acceptance criterion, in the same words as the page's own guard. A
     * skill list with prices passes; one reading "2 of 6 known" does not.
     */
    it('never shows what has not happened', () => {
        open(
            bag({
                xp: 48,
                held: 3,
                capacity: 5,
                items: [
                    {
                        item: 'fibre',
                        label: 'fibre',
                        category: 'material',
                        quantity: 3,
                        droppable: true,
                    },
                ],
                skills: [
                    {
                        skill: 'gather-fibre',
                        label: 'gather fibre',
                        price: 20,
                        known: false,
                        affordable: true,
                    },
                ],
                recipes: [
                    {
                        item: 'basket',
                        label: 'basket',
                        recipe: { fibre: 4 },
                        tool: 'handsaw',
                        buildable: false,
                    },
                ],
            }),
        );

        expect(
            screen.queryByText(
                /locked|next up|to unlock|remaining|streak|congratulation|\d+\s*%|\d+ of \d+/i,
            ),
        ).toBeNull();
    });

    /** Held-of-capacity, and never joined by the word "of". */
    it('shows what is held against what can be held', () => {
        open(bag({ held: 3, capacity: 5 }));

        expect(screen.getByText('3 / 5')).toBeInTheDocument();
        expect(screen.queryByText(/3 of 5/i)).toBeNull();
    });

    /**
     * Absent, not hidden and not greyed. A skill Blob has not met is not a slot
     * standing empty — the list only ever holds skills whose subject has
     * already been encountered.
     */
    it('draws no row for a skill whose node has not been met', () => {
        open(bag());

        expect(screen.queryByText(/gather fibre/i)).toBeNull();
        expect(screen.queryByText(/could learn/i)).toBeNull();
    });

    /**
     * An unaffordable skill stays listed WITH its price. That is a menu, not a
     * checklist: you cannot be behind on it, because there is no bottom.
     */
    it('lists an unaffordable skill with its price rather than hiding it', () => {
        open(
            bag({
                xp: 6,
                skills: [
                    {
                        skill: 'gather-fibre',
                        label: 'gather fibre',
                        price: 20,
                        known: false,
                        affordable: false,
                    },
                ],
            }),
        );

        expect(screen.getByText('gather fibre')).toBeInTheDocument();
        expect(screen.getByText('20 xp')).toBeInTheDocument();
    });

    /** A learned skill stays on the list, marked rather than removed. */
    it('keeps a learned skill listed, marked known', () => {
        open(
            bag({
                skills: [
                    {
                        skill: 'gather-fibre',
                        label: 'gather fibre',
                        price: 20,
                        known: true,
                        affordable: true,
                    },
                ],
            }),
        );

        expect(screen.getByText('gather fibre')).toBeInTheDocument();
        expect(screen.getByText('known')).toBeInTheDocument();
        expect(screen.queryByText('20 xp')).toBeNull();
    });

    /** A recipe is a price. It never says what you are short of. */
    it('shows a recipe as what it costs', () => {
        open(
            bag({
                recipes: [
                    {
                        item: 'basket',
                        label: 'basket',
                        recipe: { fibre: 4 },
                        tool: null,
                        buildable: false,
                    },
                ],
            }),
        );

        expect(screen.getByText('basket')).toBeInTheDocument();
        expect(screen.getByText('4 fibre')).toBeInTheDocument();
        expect(screen.queryByText(/need|short|missing/i)).toBeNull();
    });

    it('says an empty bag is empty, and owes nothing', () => {
        open(bag({ name: 'Pebble' }));

        expect(
            screen.getByText(/pebble is not carrying anything yet/i),
        ).toBeInTheDocument();
    });

    /** The companion's own name heads its own list. */
    it('names the companion in the list heading', () => {
        open(
            bag({
                name: 'Pebble',
                skills: [
                    {
                        skill: 'gather-fibre',
                        label: 'gather fibre',
                        price: 20,
                        known: false,
                        affordable: true,
                    },
                ],
            }),
        );

        expect(screen.getByText(/pebble could learn/i)).toBeInTheDocument();
    });

    describe('as a dialog', () => {
        it('has an accessible name and a way out', () => {
            open();

            expect(screen.getByRole('dialog')).toHaveAccessibleName('The bag');
            expect(
                screen.getByRole('button', { name: /close the bag/i }),
            ).toBeInTheDocument();
        });

        /**
         * Closed means absent, not merely invisible. Asserted on the role so a
         * modal that is only visually hidden — and therefore still in the tab
         * order, and still inside the page's own guard — fails here.
         */
        it('renders nothing at all when closed', () => {
            render(
                <CompanionBag
                    bag={bag()}
                    open={false}
                    onOpenChange={() => undefined}
                />,
            );

            expect(screen.queryByRole('dialog')).toBeNull();
        });

        it('asks to be closed rather than closing itself', () => {
            const onOpenChange = vi.fn();

            render(
                <CompanionBag
                    bag={bag()}
                    open
                    onOpenChange={onOpenChange}
                />,
            );

            fireEvent.click(
                screen.getByRole('button', { name: /close the bag/i }),
            );

            expect(onOpenChange).toHaveBeenCalledWith(false);
        });
    });

    describe('the name', () => {
        it('offers the current name for editing', () => {
            open(bag({ name: 'Pebble' }));

            expect(screen.getByLabelText(/name/i)).toHaveValue('Pebble');
        });

        /**
         * An unnamed companion gets an empty field with "Blob" as the
         * placeholder, so what clearing it does is stated rather than
         * discovered.
         */
        it('leaves the field empty when nobody has renamed it', () => {
            open(bag({ name: 'Blob' }));

            const field = screen.getByLabelText(/name/i);

            expect(field).toHaveValue('');
            expect(field).toHaveAttribute('placeholder', 'Blob');
        });
    });

    /**
     * A recipe is a PRICE, and a tool is part of what the thing costs. Not a
     * "requires" line, not an explanation and not a greyed row — the row stays
     * readable and the button alone is disabled, exactly as an unaffordable
     * skill stays listed with its price.
     */
    it('renders a recipe tool as part of the price', () => {
        open(
            bag({
                recipes: [
                    {
                        item: 'planks',
                        label: 'planks',
                        recipe: { timber: 1 },
                        tool: 'handsaw',
                        buildable: false,
                    },
                ],
            }),
        );

        expect(
            screen.getByRole('button', { name: '1 timber, handsaw' }),
        ).toBeDisabled();
    });

    /** A recipe needing nothing in hand prices only its ingredients. */
    it('prices a toolless recipe by its ingredients alone', () => {
        open(
            bag({
                recipes: [
                    {
                        item: 'basket',
                        label: 'basket',
                        recipe: { fibre: 4 },
                        tool: null,
                        buildable: true,
                    },
                ],
            }),
        );

        expect(
            screen.getByRole('button', { name: '4 fibre' }),
        ).toBeEnabled();
    });

    /**
     * Two choices this phase adds, and the reason both are here rather than in
     * the clearing: deciding how much to carry is a bag question, and the
     * clearing is already at the limit of what its labels can hold.
     */
    it('offers to tip out a carried stack, and never a tool', () => {
        open(
            bag({
                held: 4,
                items: [
                    {
                        item: 'timber',
                        label: 'timber',
                        category: 'material',
                        quantity: 3,
                        droppable: true,
                    },
                    {
                        item: 'axe',
                        label: 'axe',
                        category: 'tool',
                        quantity: 1,
                        droppable: false,
                    },
                ],
            }),
        );

        const rows = screen.getAllByRole('listitem');
        const timber = rows.find((row) => row.textContent?.includes('timber'));
        const axe = rows.find((row) => row.textContent?.includes('axe'));

        expect(timber).toBeDefined();
        expect(axe).toBeDefined();
        expect(
            within(timber as HTMLElement).getByRole('button', { name: /drop/i }),
        ).toBeInTheDocument();
        expect(
            within(axe as HTMLElement).queryByRole('button', { name: /drop/i }),
        ).toBeNull();
    });

    /** What is standing, and a control to take some of it rather than all. */
    it('lists what is standing at a node whose skill is known', () => {
        open(
            bag({
                nodes: [
                    {
                        node: 'reeds',
                        label: 'the reeds',
                        available: 6,
                        skill: 'gather-fibre',
                        met: true,
                        known: true,
                        usable: true,
                    },
                    {
                        node: 'trunk',
                        label: 'the fallen trunk',
                        available: 0,
                        skill: 'chop-wood',
                        met: true,
                        known: true,
                        usable: true,
                    },
                    {
                        node: 'deadfall',
                        label: 'the fallen branches',
                        available: 4,
                        skill: 'gather-wood',
                        met: false,
                        known: false,
                        usable: false,
                    },
                ],
            }),
        );

        expect(screen.getByText('the reeds')).toBeInTheDocument();
        expect(
            screen.getByRole('spinbutton', { name: /how much to take from the reeds/i }),
        ).toBeInTheDocument();

        // Nothing standing, and a node whose skill is not known, are both
        // absent rather than listed at zero or greyed.
        expect(screen.queryByText('the fallen trunk')).toBeNull();
        expect(screen.queryByText('the fallen branches')).toBeNull();
    });

    /**
     * The human partner's decision: keep the row, disable the button. A node
     * whose skill is known but whose tool is not yet held stays listed and
     * readable — only its take button greys, exactly as an unbuildable
     * recipe's button does.
     */
    it('lists an unusable node with its button disabled rather than hiding the row', () => {
        open(
            bag({
                nodes: [
                    {
                        node: 'trunk',
                        label: 'the fallen trunk',
                        available: 3,
                        skill: 'chop-wood',
                        met: true,
                        known: true,
                        usable: false,
                    },
                ],
            }),
        );

        expect(screen.getByText('the fallen trunk')).toBeInTheDocument();

        const row = screen.getByText('the fallen trunk').closest('li');

        expect(row).not.toBeNull();
        expect(
            within(row as HTMLElement).getByRole('button', {
                name: /take from the fallen trunk/i,
            }),
        ).toBeDisabled();
    });
});
