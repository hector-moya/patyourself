import { act, render, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { __resetSpriteClock, useSpriteClock } from '@/hooks/use-sprite-clock';

import { ANIMATIONS } from './companion-animations';
import { CompanionRoom, partOfDay } from './companion-room';
import { companion } from './companion.fixture';
import { SCENES, sceneFor } from './scenes';

const ROOM = companion().room;

/**
 * The foliage reads the shared clock, which is a module-level singleton, so
 * these tests drive requestAnimationFrame by hand rather than waiting on real
 * frames — the same harness `use-sprite-clock.test.ts` uses. Stubbed for the
 * whole file, not just the foliage block: every forest render subscribes, and
 * a subscription left running would outlive the test that made it.
 */
let pending: FrameRequestCallback[] = [];
let handle = 0;

function tick(now: number): void {
    const due = pending;
    pending = [];

    act(() => {
        for (const callback of due) {
            callback(now);
        }
    });
}

beforeEach(() => {
    pending = [];
    handle = 0;

    vi.stubGlobal(
        'requestAnimationFrame',
        (callback: FrameRequestCallback): number => {
            pending.push(callback);

            return ++handle;
        },
    );
    vi.stubGlobal('cancelAnimationFrame', vi.fn());
});

afterEach(() => {
    __resetSpriteClock();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

/**
 * A whole day, in the same shape as `config('companion.room')`, for the tests
 * that need four parts to tell apart.
 *
 * Passed explicitly rather than folded into the shared fixture: that default
 * has only three parts, and the `partOfDay` tests below depend on hour 6
 * wrapping to `night` because no `sunrise` entry exists to claim it.
 */
const FULL_DAY = {
    sunrise: {
        from: 5,
        wall: '#F2E0D0',
        window: '#F0B98A',
        light: '#F4A15C',
        dim: 0.18,
    },
    day: {
        from: 8,
        wall: '#EFE6D6',
        window: '#B9D5E4',
        light: '#FFFFFF',
        dim: 0,
    },
    dusk: {
        from: 18,
        wall: '#E7D2BE',
        window: '#E9A468',
        light: '#E2762F',
        dim: 0.22,
    },
    night: {
        from: 21,
        wall: '#2F3A40',
        window: '#1A2530',
        light: '#2B3F6B',
        dim: 0.42,
    },
};

function room(overrides = {}, hour = 12) {
    return render(
        <CompanionRoom
            companion={companion(overrides)}
            animation="idle"
            frame={0}
            hour={hour}
        />,
    ).container;
}

describe('partOfDay', () => {
    it('reads the hour against the parts config defines', () => {
        expect(partOfDay(9, ROOM)).toBe('day');
        expect(partOfDay(17, ROOM)).toBe('day');
        expect(partOfDay(19, ROOM)).toBe('dusk');
        expect(partOfDay(22, ROOM)).toBe('night');
    });

    /**
     * The small hours are still night. Wrapping past midnight falls out of
     * reading the parts in order rather than out of a fourth state describing
     * 3am.
     */
    it('wraps past midnight without a fourth state', () => {
        expect(partOfDay(0, ROOM)).toBe('night');
        expect(partOfDay(3, ROOM)).toBe('night');
        expect(partOfDay(6, ROOM)).toBe('night');
    });
});

describe('CompanionRoom', () => {
    it('renders nothing before Blob exists', () => {
        const { container } = render(
            <CompanionRoom
                companion={companion({ features: [], unlocks: [] })}
                animation="idle"
                frame={0}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('stands Blob on the floor of a room with a window', () => {
        const container = room({ features: ['blob', 'legs'] });

        expect(container.querySelector('.blob-room')).not.toBeNull();
        expect(container.querySelector('.blob-body')).not.toBeNull();
    });

    /**
     * The cheapest liveness in the feature: the room looks different at
     * breakfast and at dinner without the user doing anything.
     */
    it('tints the wall and window by the time of day', () => {
        const day = room({}, 12);
        const night = room({}, 23);

        expect(
            day.querySelector('.blob-room')?.getAttribute('data-part-of-day'),
        ).toBe('day');
        expect(
            night.querySelector('.blob-room')?.getAttribute('data-part-of-day'),
        ).toBe('night');

        expect(day.innerHTML).toContain('#EFE6D6');
        expect(night.innerHTML).toContain('#2F3A40');
        expect(night.innerHTML).not.toContain('#EFE6D6');
    });

    it('puts an earned object in the room', () => {
        const container = room({ room_objects: ['bookshelf'] });

        expect(
            container.querySelector('[data-room-object="bookshelf"]'),
        ).not.toBeNull();
    });

    /**
     * The rule the whole room turns on. An unearned object is not drawn greyed
     * out, not silhouetted and not as an empty slot — a room with two things
     * and six grey outlines is a task list.
     */
    it('never draws an object that has not been earned', () => {
        const container = room({ room_objects: [] });

        expect(container.querySelector('[data-room-object]')).toBeNull();
        expect(container.querySelectorAll('[data-room-object]')).toHaveLength(
            0,
        );
    });

    it('skips a room object no dictionary entry draws yet', () => {
        const container = room({ room_objects: ['aquarium'] });

        expect(container.querySelector('[data-room-object]')).toBeNull();
        expect(container.querySelector('.blob-body')).not.toBeNull();
    });
});

describe('scenes', () => {
    it('draws the forest backdrop for a record that is still outside', () => {
        // room_objects is set here (rather than left at the fixture's
        // default empty list) so this is a real test of the guard: without
        // it, the bookshelf assertion below would pass whether or not the
        // forest actually excludes ROOM_OBJECTS.
        const container = room({
            scene: 'forest',
            room_objects: ['bookshelf'],
        });

        expect(container.querySelector('.scene-backdrop')).not.toBeNull();
        expect(container.querySelector('.room-object--bookshelf')).toBeNull();
    });

    it('draws the room, with everything in it, for a record that is indoors', () => {
        const container = room({
            scene: 'cabin',
            room_objects: ['bookshelf'],
        });

        expect(
            container.querySelector('.room-object--bookshelf'),
        ).not.toBeNull();
    });

    /**
     * Every other test in this file renders the forest (if at all) at noon,
     * so a renamed part, a dropped one, or two parts silently pointed at the
     * same PNG would all ship unnoticed — the reader would just see flat
     * green at night. This walks the whole day and checks both that a
     * backdrop actually loads at each part and that no two parts show the
     * same art.
     */
    it('draws a distinct backdrop for every part of the day, not just noon', () => {
        const hrefs = [6, 12, 19, 23].map((hour) => {
            const backdrop = room(
                { scene: 'forest', room: FULL_DAY },
                hour,
            ).querySelector('.scene-backdrop');

            expect(backdrop).not.toBeNull();

            return backdrop?.getAttribute('href');
        });

        expect(new Set(hrefs).size).toBe(4);
    });
});

describe('foliage', () => {
    /**
     * The placements are derived from the room's own grid and written up in
     * `scenes/README.md`, and until here they were pinned only at the data
     * end: `scenes.test.ts` checks the registry's numbers are in bounds and
     * never that any of them reaches the DOM. A layer drawn at the origin, off
     * a sheet that does not exist, or with its cell squared off is a picture
     * problem that no arithmetic notices.
     *
     * Every expected value is read back out of the registry entry, so this
     * cannot drift from `scenes.ts` — it says the drawing agrees with the
     * declaration, not that either equals some number copied here. `width` and
     * `height` are asserted separately and in that order because the failure
     * worth catching is the two swapped, which a pair compared as a set would
     * miss. The sheet spans every frame it has, because a sheet drawn one cell
     * wide leaves the window sitting on a stretched frame 0 forever.
     */
    it('draws every layer the scene declares', () => {
        const container = room({ scene: 'forest' });
        const drawn = [...container.querySelectorAll('.scene-foliage')];

        // A count against an empty list is `expect(0).toBe(0)`. The list has
        // to have something in it for the comparison below to mean anything.
        expect(SCENES.forest.foliage.length).toBeGreaterThan(0);
        expect(drawn).toHaveLength(SCENES.forest.foliage.length);

        SCENES.forest.foliage.forEach((layer, index) => {
            const node = drawn[index];
            const image = node.querySelector('image');
            const [cellWidth, cellHeight] = layer.cell;

            expect(node.getAttribute('x')).toBe(String(layer.at[0]));
            expect(node.getAttribute('y')).toBe(String(layer.at[1]));
            expect(node.getAttribute('width')).toBe(String(cellWidth));
            expect(node.getAttribute('height')).toBe(String(cellHeight));

            expect(image?.getAttribute('href')).toBe(layer.sheet);
            expect(image?.getAttribute('width')).toBe(
                String(ANIMATIONS[layer.animation].frames * cellWidth),
            );
            expect(image?.getAttribute('height')).toBe(String(cellHeight));
        });
    });

    /**
     * Replaces a `draws none indoors` that could not be made red: the cabin's
     * foliage list is empty, so drawing nothing indoors is what the data does
     * on its own and the `!indoors` gate could be deleted with the whole suite
     * still green.
     *
     * What is worth asserting is the mechanism rather than the cabin's current
     * emptiness — the component draws the list belonging to the scene the
     * record names, whichever scene that is. Both counts are read from
     * `sceneFor`, so this keeps its meaning on the day E3 hands the cabin a
     * lamp flame to flicker, where "none indoors" would have to be deleted.
     */
    it("draws the foliage its own scene declares, and no other scene's", () => {
        for (const name of ['forest', 'cabin']) {
            expect(
                room({ scene: name }).querySelectorAll('.scene-foliage'),
            ).toHaveLength(sceneFor(name).foliage.length);
        }

        // Two scenes that declared the same number of layers would let a
        // component drawing one scene's list everywhere pass the loop above.
        expect(sceneFor('forest').foliage.length).toBeGreaterThan(0);
        expect(sceneFor('forest').foliage.length).not.toBe(
            sceneFor('cabin').foliage.length,
        );
    });

    /**
     * The wind comes from the shared clock, not from the `frame` prop this
     * component is handed — that one drives Blob and stops there. So the clock
     * is what this drives, and a version of this test that re-rendered with a
     * different `frame` would pass against a tree nailed to frame 0.
     */
    it('advances on the shared clock rather than on the frame prop', () => {
        const container = room({ scene: 'forest' });
        const tree = container.querySelector('.scene-foliage');

        expect(tree).not.toBeNull();

        tick(0);
        const held = tree?.getAttribute('viewBox');

        expect(held).not.toBeNull();

        // sway is 12 frames at 3fps: a third of a second to the next one.
        tick(400);

        expect(tree?.getAttribute('viewBox')).not.toBe(held);
    });

    /**
     * The clock derives its frame from the absolute timestamp so two Blobs
     * cannot drift apart, which means three tufts on one sheet and one
     * animation would move as one — the metronome this layer exists to avoid.
     */
    it('offsets tufts that share a sheet so they do not move as one', () => {
        const container = room({ scene: 'forest' });
        const drawn = [...container.querySelectorAll('.scene-foliage')];

        const tufts = SCENES.forest.foliage
            .map((layer, index) => ({ layer, node: drawn[index] }))
            .filter(({ layer }) => layer.animation === 'rustle');

        expect(tufts.length).toBeGreaterThan(1);
        expect(new Set(tufts.map(({ layer }) => layer.phase ?? 0)).size).toBe(
            tufts.length,
        );

        tick(0);

        const shown = new Set(
            tufts.map(({ node }) => node?.getAttribute('viewBox')),
        );

        expect(shown.size).toBe(tufts.length);
    });

    /**
     * The wash is the last child of the room, so anything drawn after it stays
     * bright at midnight. Blob is in front of the trees for the same reason
     * he is in front of the backdrop — he is standing in the clearing, not
     * behind it.
     *
     * The backdrop end matters at least as much and was the clause going
     * unchecked: it is an opaque 144×114 PNG over the whole room, so foliage
     * drawn before it is not dimmed or clipped, it is gone, and the screen
     * looks exactly like a scene that has no foliage at all.
     */
    it('draws over the backdrop, under Blob and under the light', () => {
        const container = room({ scene: 'forest' }, 23);
        const backdrop = container.querySelector('.scene-backdrop');
        const light = container.querySelector('.scene-light');
        const blob = container.querySelector('.blob-anim');
        const drawn = container.querySelectorAll('.scene-foliage');

        expect(backdrop).not.toBeNull();
        expect(light).not.toBeNull();
        expect(blob).not.toBeNull();
        expect(drawn.length).toBeGreaterThan(0);

        for (const node of drawn) {
            expect(
                node.compareDocumentPosition(backdrop as Node) &
                    Node.DOCUMENT_POSITION_PRECEDING,
            ).toBeTruthy();
            expect(
                node.compareDocumentPosition(blob as Node) &
                    Node.DOCUMENT_POSITION_FOLLOWING,
            ).toBeTruthy();
            expect(
                node.compareDocumentPosition(light as Node) &
                    Node.DOCUMENT_POSITION_FOLLOWING,
            ).toBeTruthy();
        }
    });

    /**
     * A sheet swaps whole frames, and easing between two of them is what makes
     * pixel art look wrong — the rule the sprite renderer already holds itself
     * to. Drawn with the sprite renderer because the SVG one eases its own
     * body on purpose, which would swamp the assertion.
     */
    it('applies no transition to anything in the scene', () => {
        const container = room({ scene: 'forest', renderer: 'sprite' }, 23);

        expect(
            container.querySelectorAll('.scene-foliage').length,
        ).toBeGreaterThan(0);

        for (const node of container.querySelectorAll('*')) {
            expect(node.getAttribute('style') ?? '').not.toContain(
                'transition',
            );
        }
    });

    /**
     * The whole reason the foliage reads an existing clock instead of starting
     * one. Four layers and a Blob share the frame budget one loop costs.
     */
    it('adds no second rAF loop, whatever is on screen', () => {
        renderHook(() => useSpriteClock('idle'));

        const container = room({ scene: 'forest' });

        expect(
            container.querySelectorAll('.scene-foliage').length,
        ).toBeGreaterThan(0);
        expect(pending).toHaveLength(1);

        tick(0);

        expect(pending).toHaveLength(1);
    });
});

describe('scene light', () => {
    it('washes the whole scene, Blob included, after dark', () => {
        const container = room({ scene: 'forest' }, 23);
        const light = container.querySelector('.scene-light') as SVGRectElement;
        const blob = container.querySelector('.blob-anim') as SVGElement;

        expect(light).not.toBeNull();
        // Drawn last, so it covers Blob rather than sitting behind it.
        expect(
            blob.compareDocumentPosition(light) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
    });

    /**
     * The fixture draws Blob with the SVG renderer; config ships `sprite`. The
     * reversal this light exists to make — the creature lit with the world
     * rather than exempt from it — is worth nothing if it is only ever checked
     * against the Blob the reader never sees.
     */
    it('washes Blob the sprite renderer draws, not only the SVG one', () => {
        const container = room({ scene: 'forest', renderer: 'sprite' }, 23);
        const light = container.querySelector('.scene-light') as SVGRectElement;
        const blob = container.querySelector('.blob-anim') as SVGElement;

        expect(container.querySelector('.blob-sprite')).not.toBeNull();
        expect(
            blob.compareDocumentPosition(light) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
    });

    it('washes the cabin too, so Blob is lit the same indoors', () => {
        const container = room(
            { scene: 'cabin', room_objects: ['bookshelf'] },
            23,
        );

        expect(container.querySelector('.scene-light')).not.toBeNull();
    });

    /**
     * Midday needs no help. An overlay at noon would tint Blob for nothing.
     */
    it('draws no light at midday', () => {
        expect(
            room({ scene: 'forest' }, 12).querySelector('.scene-light'),
        ).toBeNull();
    });

    it('uses a different light at each part of the day', () => {
        const fillAt = (hour: number) =>
            room({ scene: 'forest', room: FULL_DAY }, hour)
                .querySelector('.scene-light')
                ?.getAttribute('fill');

        expect(new Set([fillAt(6), fillAt(19), fillAt(23)]).size).toBe(3);
        // Distinctness alone cannot tell `light` from `wall` or `window`:
        // every field of the palette differs across parts, so the wash reads
        // as varying no matter which of them it was wired to.
        expect(fillAt(23)).toBe(FULL_DAY.night.light);
    });

    /**
     * Nothing else holds this value in place, and the failure is not a scene
     * a little too dim — at full strength a multiply leaves night unreadable.
     */
    it('dims by the amount the part of the day carries, not some other amount', () => {
        const opacityAt = (hour: number) =>
            room({ scene: 'forest', room: FULL_DAY }, hour)
                .querySelector('.scene-light')
                ?.getAttribute('opacity');

        expect(opacityAt(23)).toBe(String(FULL_DAY.night.dim));
        expect(opacityAt(6)).toBe(String(FULL_DAY.sunrise.dim));
    });

    /**
     * Order says the wash is on top of Blob; it says nothing about the wash
     * reaching him. A rect one pixel wide is still drawn last.
     */
    it('covers the whole room, not just the corner it starts in', () => {
        const container = room({ scene: 'forest' }, 23);
        const light = container.querySelector('.scene-light');

        // The room's viewBox, written out because `ROOM` is private to
        // companion-room.tsx — and the `blob-room` svg's own `viewBox` is what
        // these have to agree with, so that agreement is asserted here too
        // rather than left to drift.
        expect(light?.getAttribute('x')).toBe('-72');
        expect(light?.getAttribute('y')).toBe('-38');
        expect(light?.getAttribute('width')).toBe('144');
        expect(light?.getAttribute('height')).toBe('114');
        expect(
            container.querySelector('.blob-room')?.getAttribute('viewBox'),
        ).toBe('-72 -38 144 114');
    });
});

describe('room objects', () => {
    it('draws an object the record has earned', () => {
        expect(
            room({ room_objects: ['rug'] }).querySelector('.room-object--rug'),
        ).not.toBeNull();
    });

    it('skips an object it does not know rather than leaving a gap', () => {
        const container = room({ room_objects: ['spaceship'] });

        expect(container.querySelector('.room-object--spaceship')).toBeNull();
        // The room itself still renders.
        expect(container.querySelector('svg')).not.toBeNull();
    });

    it('draws every object the record has earned, at once', () => {
        const container = room({
            room_objects: ['bookshelf', 'rug', 'lamp', 'plant', 'stool'],
        });

        expect(
            container.querySelectorAll('[class*="room-object--"]'),
        ).toHaveLength(5);
    });
});
