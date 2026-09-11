import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { __resetSpriteClock } from '@/hooks/use-sprite-clock';
import {
    Companion,
    actionsFor,
    ambientFor,
    arrivingItem,
    describe as label,
    selfStartedFor,
} from './companion';
import { companion, unlock } from './companion.fixture';
import type { RoomPalette } from './part-of-day';

/**
 * A four-part day in the shape config ships, built locally: the shared
 * fixture's room has three parts and no sunrise, so `day` is what follows its
 * night — fine for the room's own tests, wrong for asserting when Blob wakes.
 */
function fourPartDay(): Record<string, RoomPalette> {
    return {
        sunrise: { from: 5, wall: '#F2E0D0', window: '#F0B98A', light: '#F4A15C', dim: 0.18 },
        day: { from: 8, wall: '#EFE6D6', window: '#B9D5E4', light: '#FFFFFF', dim: 0 },
        dusk: { from: 18, wall: '#E7D2BE', window: '#E9A468', light: '#E2762F', dim: 0.22 },
        night: { from: 21, wall: '#2F3A40', window: '#1A2530', light: '#2B3F6B', dim: 0.42, asleep: true },
    };
}

/** Hours that land in each part of the day above. */
const ASLEEP = 23;
const WAKING = 6;
const AWAKE = 13;

// The clock behind Companion is a module-level singleton (see
// use-sprite-clock.test.ts). Only one test below drives it by hand with a
// stubbed requestAnimationFrame, but the teardown runs unconditionally so a
// failed assertion still can't leak a stubbed clock into the next test.
//
// Several tests in this file pin the wall clock with `vi.useFakeTimers` so
// `ambientFor`'s default hour argument is deterministic rather than whatever
// hour the suite happens to run at — `vi.useRealTimers()` here means a failed
// assertion mid-test can't leak a fake clock into the next one either.
afterEach(() => {
    vi.unstubAllGlobals();
    __resetSpriteClock();
    vi.useRealTimers();
});

/**
 * Same stub as use-sprite-clock.test.ts: jsdom has no real matchMedia, so this
 * overwrites the never-matching stub from test/setup.ts for the duration of a
 * test. Reset to `false` before every test in this file — a direct assignment
 * outlives `vi.unstubAllGlobals()`, which only undoes `vi.stubGlobal` stubs.
 */
function reduceMotion(matches: boolean): void {
    window.matchMedia = ((query: string) =>
        ({
            matches: matches && query.includes('prefers-reduced-motion'),
            media: query,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
        }) as unknown as MediaQueryList) as typeof window.matchMedia;
}

beforeEach(() => {
    reduceMotion(false);
});

describe('Companion', () => {
    /**
     * Before the first outcome there is no Blob, and no outline of one either.
     * A placeholder in the shape of a reward is a thing the user is behind on.
     */
    it('renders nothing before Blob exists', () => {
        const { container } = render(
            <Companion
                companion={companion({
                    features: [],
                    stage_index: 0,
                    unlocks: [],
                    latest_unlock: null,
                })}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    /**
     * `ambientFor` defaults to the wall clock, so this pins it to a daytime
     * hour — only `Date` is faked, leaving the real `requestAnimationFrame`
     * and `setTimeout` driving the sprite clock and auto-timer untouched.
     * Without the pin this reads 'sleep' for anyone running the suite at
     * night.
     */
    it('puts a viewBox around the drawing and runs the clock', () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-09-11T13:00:00'));

        const { container } = render(<Companion companion={companion()} />);

        const svg = container.querySelector('svg');

        expect(svg?.getAttribute('viewBox')).toBe('-32 -22 64 84');
        expect(
            svg?.querySelector('.blob-anim')?.getAttribute('data-animation'),
        ).toBe('idle');
    });

    /**
     * One component in both places. The geometry lives in the viewBox, so the
     * corner instance and the full-size one are the same drawing.
     */
    it('is the same drawing at 32px and at 300px', () => {
        const small = render(
            <Companion companion={companion()} size={32} />,
        ).container.querySelector('svg');
        const large = render(
            <Companion companion={companion()} size={300} />,
        ).container.querySelector('svg');

        expect(small?.getAttribute('viewBox')).toBe(
            large?.getAttribute('viewBox'),
        );
        expect(small?.getAttribute('width')).toBe('32');
        expect(large?.getAttribute('width')).toBe('300');
    });

    it('honours the renderer flag from config', () => {
        const { container } = render(
            <Companion companion={companion({ renderer: 'sprite' })} />,
        );

        expect(container.querySelector('.blob-body')).toBeNull();
    });

    it('describes what Blob has, without scoring it', () => {
        render(
            <Companion
                companion={companion({
                    features: ['blob', 'legs'],
                    items: [{ type: 'shoes', variant: null }],
                    abilities: ['walk'],
                })}
            />,
        );

        const description =
            screen.getByRole('img').getAttribute('aria-label') ?? '';

        expect(description).toBe('Blob, with shoes, walk');
        expect(description).not.toMatch(/streak|level|%|of \d/i);
    });

    /**
     * The reward has to arrive with the act that earned it. A number rather
     * than a boolean because two outcomes logged in a row have to fire twice,
     * and a flag that is already `true` never changes.
     */
    it('reacts when handed an outcome id', () => {
        // Pinned to daytime: the initial-idle assertion below reads the wall
        // clock through `ambientFor`'s default hour.
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-09-11T13:00:00'));

        const { container, rerender } = render(
            <Companion companion={companion()} reactTo={null} />,
        );

        expect(
            container
                .querySelector('.blob-anim')
                ?.getAttribute('data-animation'),
        ).toBe('idle');

        rerender(<Companion companion={companion()} reactTo={101} />);

        expect(
            container
                .querySelector('.blob-anim')
                ?.getAttribute('data-animation'),
        ).toBe('notice');
    });

    it('reacts again when a second outcome is logged', () => {
        // Pinned to daytime: this test asserts 'idle' once the reaction ends
        // (below), and 'idle' vs 'sleep' comes from `ambientFor`'s default
        // hour. Only `Date` is faked — the requestAnimationFrame stub below
        // still has to be driven by hand, exactly as it would on a real
        // clock.
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-09-11T13:00:00'));

        // The shared clock only advances when something drives its
        // requestAnimationFrame loop, so proving the first reaction actually
        // ends means driving it by hand rather than assuming it did — see
        // use-sprite-clock.test.ts for the pattern this borrows.
        let pending: FrameRequestCallback[] = [];
        let handle = 0;

        vi.stubGlobal(
            'requestAnimationFrame',
            (callback: FrameRequestCallback): number => {
                pending.push(callback);

                return ++handle;
            },
        );
        vi.stubGlobal('cancelAnimationFrame', vi.fn());

        const tick = (now: number) => {
            const due = pending;
            pending = [];

            act(() => {
                for (const callback of due) {
                    callback(now);
                }
            });
        };

        const { container, rerender } = render(
            <Companion companion={companion()} reactTo={101} />,
        );

        // Establishes the reaction's start time on the clock.
        tick(0);

        expect(
            container
                .querySelector('.blob-anim')
                ?.getAttribute('data-animation'),
        ).toBe('notice');

        // notice is 4 frames at 8fps: 500ms finishes it and hands the channel
        // back to the ambient. Asserted explicitly, so this proves the first
        // reaction ended rather than assuming it.
        tick(500);

        expect(
            container
                .querySelector('.blob-anim')
                ?.getAttribute('data-animation'),
        ).toBe('idle');

        rerender(<Companion companion={companion()} reactTo={102} />);

        expect(
            container
                .querySelector('.blob-anim')
                ?.getAttribute('data-animation'),
        ).toBe('notice');
    });

    /**
     * A plain visit, with nothing just recorded, must not make Blob move.
     * Pinned to daytime: the assertion reads 'idle', which comes from
     * `ambientFor`'s default (wall-clock) hour.
     */
    it('does not react without an outcome id', () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-09-11T13:00:00'));

        const { container, rerender } = render(
            <Companion companion={companion()} />,
        );

        rerender(<Companion companion={companion()} />);

        expect(
            container
                .querySelector('.blob-anim')
                ?.getAttribute('data-animation'),
        ).toBe('idle');
    });

    /**
     * `notice` is unprompted — nobody pressed a button, an outcome was just
     * logged elsewhere. Under reduced motion there is no loop left to run the
     * one-shot back down once it lands, so firing it here would leave Blob
     * stuck in the noticed pose for the rest of the page's life. The correct
     * behaviour is silence: Blob stays on the ambient, exactly as if `reactTo`
     * had never changed.
     */
    it('does not react to a new outcome id under reduced motion', () => {
        // Pinned to daytime: the held pose asserted below is 'idle', which
        // comes from `ambientFor`'s default (wall-clock) hour.
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-09-11T13:00:00'));
        reduceMotion(true);

        const { container, rerender } = render(
            <Companion companion={companion()} reactTo={null} />,
        );

        rerender(<Companion companion={companion()} reactTo={101} />);

        const blob = container.querySelector('.blob-anim');

        expect(blob?.getAttribute('data-animation')).toBe('idle');
        expect(blob?.getAttribute('data-frame')).toBe('0');
    });

    /**
     * A reaction always wins, and hands the channel back to whatever the ambient
     * is now — which at night is sleep, not idle. This is what makes the 11pm
     * visit safe: log an outcome and Blob stirs exactly as it does at noon.
     */
    it('reacts at night and goes back to sleep, not to standing', () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-09-11T23:30:00'));

        let pending: FrameRequestCallback[] = [];
        let handle = 0;

        vi.stubGlobal(
            'requestAnimationFrame',
            (callback: FrameRequestCallback): number => {
                pending.push(callback);

                return ++handle;
            },
        );
        vi.stubGlobal('cancelAnimationFrame', vi.fn());

        const tick = (now: number) => {
            const due = pending;
            pending = [];

            act(() => {
                for (const callback of due) {
                    callback(now);
                }
            });
        };

        const { container } = render(
            <Companion companion={companion({ room: fourPartDay() })} reactTo={101} />,
        );

        tick(0);

        expect(
            container.querySelector('.blob-anim')?.getAttribute('data-animation'),
        ).toBe('notice');

        // notice is 4 frames at 8fps: 500ms finishes it and hands the channel back.
        tick(500);

        expect(
            container.querySelector('.blob-anim')?.getAttribute('data-animation'),
        ).toBe('sleep');
    });

    /**
     * Reduced motion holds frame 0 and never advances, which for sleep is exactly
     * right: it is a state, and a held sleeping pose is the rest state that
     * preference asks for. What must not happen is the held pose being idle.
     */
    it('holds the sleeping pose under reduced motion', () => {
        reduceMotion(true);
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-09-11T23:30:00'));

        const { container } = render(
            <Companion companion={companion({ room: fourPartDay() })} />,
        );

        const blob = container.querySelector('.blob-anim');

        expect(blob?.getAttribute('data-animation')).toBe('sleep');
        expect(blob?.getAttribute('data-frame')).toBe('0');
    });
});

describe('ambientFor', () => {
    /**
     * Walking is what Blob does at rest once it can walk. Same channel.
     *
     * Pinned to an hour inside the fixture's `day` part rather than left on
     * the default clock: before the fixture's `day` starts at 7, nothing has
     * "started" yet and `partOfDay` falls back to `night`, which is asleep —
     * this would otherwise go red for anyone running the suite before 7am.
     */
    it('is walk once walking is unlocked, and idle before that', () => {
        expect(ambientFor(companion(), AWAKE)).toBe('idle');
        expect(ambientFor(companion({ abilities: ['walk'] }), AWAKE)).toBe(
            'walk',
        );
    });
});

describe('ambientFor, on the clock', () => {
    it('sleeps through the part config marks asleep', () => {
        const data = companion({ room: fourPartDay() });

        expect(ambientFor(data, ASLEEP)).toBe('sleep');
        expect(ambientFor(data, 2)).toBe('sleep');
    });

    it('is idle or walking at every other hour', () => {
        const data = companion({ room: fourPartDay() });

        expect(ambientFor(data, WAKING)).toBe('idle');
        expect(ambientFor(data, AWAKE)).toBe('idle');
        expect(ambientFor({ ...data, abilities: ['walk'] }, AWAKE)).toBe('walk');
    });

    /** Sleeping is not a skill, so it needs nothing earned. */
    it('sleeps with nothing earned at all', () => {
        expect(
            ambientFor(companion({ room: fourPartDay(), abilities: [] }), ASLEEP),
        ).toBe('sleep');
    });

    /** Two rungs, and sleep is the first of them: it is a state of the whole
     *  creature, where walking is what an awake Blob does. */
    it('sleeps rather than walks, once walking is earned', () => {
        expect(
            ambientFor(
                companion({ room: fourPartDay(), abilities: ['walk'] }),
                ASLEEP,
            ),
        ).toBe('sleep');
    });

    /**
     * THE MIRROR GUARD. Blob's life is never a mirror: what it is doing must
     * be a function of the clock and of config, and of nothing in the record.
     * A sleeping Blob is safe because it is night; it becomes a rebuke the
     * moment it correlates with the person.
     *
     * The mutation this kills is any read of the record inside either
     * selector — a gate on log_count, a check for how long since the last
     * outcome, anything at all.
     */
    it('reads the clock and nothing about the record', () => {
        const room = fourPartDay();
        const empty = companion({ room, log_count: 1, insight_count: 0, unlocks: [] });
        const deep = companion({
            room,
            log_count: 900,
            insight_count: 50,
            stage_index: 13,
            unlocks: [unlock(), unlock({ kind: 'ability', name: 'walk' })],
            latest_unlock: unlock({ kind: 'ability', name: 'walk' }),
        });

        for (const hour of [0, WAKING, AWAKE, 19, ASLEEP]) {
            expect(ambientFor(empty, hour)).toBe(ambientFor(deep, hour));
            expect(selfStartedFor(empty, hour)).toEqual(selfStartedFor(deep, hour));
        }
    });
});

describe('selfStartedFor, on the clock', () => {
    /**
     * The subtle one. `blink` has a row on every form and fires for every
     * Blob that exists, so left alone the auto-timer opens a sleeping Blob's
     * eyes for an eighth of a second every few seconds — a sleeper with a
     * twitching eyelid, and nothing about the pose or the class names would
     * have shown it.
     */
    it('starts nothing at all while Blob is asleep', () => {
        const data = companion({
            room: fourPartDay(),
            abilities: ['walk', 'wave', 'jump'],
        });

        expect(selfStartedFor(data, ASLEEP)).toEqual([]);
        expect(selfStartedFor(data, ASLEEP)).not.toContain('blink');
    });

    it('stretches only in the part that follows sleeping', () => {
        const data = companion({ room: fourPartDay() });

        expect(selfStartedFor(data, WAKING)).toContain('stretch');
        expect(selfStartedFor(data, AWAKE)).not.toContain('stretch');
        expect(selfStartedFor(data, 19)).not.toContain('stretch');
        expect(selfStartedFor(data, ASLEEP)).not.toContain('stretch');
    });

    /** Being alive rather than a skill, both of them, so neither waits on
     *  the ladder. */
    it('blinks and looks around at every awake hour, with nothing earned', () => {
        const data = companion({ room: fourPartDay(), abilities: [] });

        for (const hour of [WAKING, AWAKE, 19]) {
            expect(selfStartedFor(data, hour)).toContain('blink');
            expect(selfStartedFor(data, hour)).toContain('look');
        }
    });

    it('still adds the earned one-shots while awake', () => {
        const data = companion({ room: fourPartDay(), abilities: ['wave'] });

        expect(selfStartedFor(data, AWAKE)).toContain('wave');
        expect(selfStartedFor(data, ASLEEP)).not.toContain('wave');
    });
});

describe('the button row', () => {
    /**
     * A Sleep button at two in the afternoon is the user putting the creature
     * down, and Blob never asks to be pressed. Neither of the other two is
     * something to ask for either: they happen or they do not.
     *
     * `actionsFor` takes no hour and must not gain one — what can be asked of
     * Blob is a question about what it has learned, not about the time. So
     * this asserts the absence once, against a Blob that has earned
     * everything the row can offer.
     */
    it('never offers sleeping, stretching or looking around', () => {
        const names = actionsFor(
            companion({ room: fourPartDay(), abilities: ['walk', 'wave', 'jump'] }),
        ).map((action) => action.animation);

        expect(names).toEqual(['pet', 'play', 'wave', 'jump']);
    });
});

describe('arrivingItem', () => {
    it('is the latest unlock when that was something Blob wears', () => {
        expect(
            arrivingItem(
                companion({
                    latest_unlock: unlock({
                        kind: 'item',
                        name: 'scarf',
                        variant: 'coral',
                    }),
                }),
            ),
        ).toEqual({ type: 'scarf', variant: 'coral' });
    });

    it('is nothing when the latest unlock was not worn', () => {
        expect(
            arrivingItem(
                companion({
                    latest_unlock: unlock({ kind: 'ability', name: 'walk' }),
                }),
            ),
        ).toBeNull();
    });
});

describe('describe', () => {
    it('names Blob alone when it owns nothing yet', () => {
        expect(label(companion())).toBe('Blob');
    });
});

describe('selfStartedFor', () => {
    /**
     * Pinned to a dusk hour rather than left on the default clock: the shared
     * fixture's room has no sunrise, so its `day` part directly follows the
     * asleep `night` and would count as `wakingAt` for every daytime hour —
     * which would smuggle `stretch` into these assertions and make them pass
     * or fail by the hour the suite happens to run at. Dusk sidesteps both
     * without pulling in the four-part room the clock tests below use.
     */
    it('lets every Blob blink and look, and nothing else, before anything is earned', () => {
        expect(selfStartedFor(companion({ abilities: [] }), 19)).toEqual([
            'blink',
            'look',
        ]);
    });

    it('adds an ability that has a self-starting animation', () => {
        expect(
            selfStartedFor(companion({ abilities: ['wave'] }), 19),
        ).toContain('wave');
    });

    it('ignores an ability that has no animation to start', () => {
        // `carry` is a prop, not a pose: it draws, but it never plays.
        expect(selfStartedFor(companion({ abilities: ['carry'] }), 19)).toEqual([
            'blink',
            'look',
        ]);
    });

    it('ignores `walk`, which is the ambient rather than a one-shot', () => {
        expect(selfStartedFor(companion({ abilities: ['walk'] }), 19)).toEqual([
            'blink',
            'look',
        ]);
    });
});

describe('actionsFor', () => {
    const names = (data: Parameters<typeof actionsFor>[0]) =>
        actionsFor(data).map((action) => action.animation);

    it('always offers the two things done to Blob rather than by it', () => {
        expect(names(companion({ abilities: [] }))).toEqual(['pet', 'play']);
    });

    it('offers an ability once Blob has learned it', () => {
        expect(names(companion({ abilities: ['wave'] }))).toContain('wave');
        expect(names(companion({ abilities: ['jump'] }))).toContain('jump');
    });

    /**
     * The rule the whole row turns on. An ability Blob has not learned is
     * absent, not disabled — a greyed button is an empty slot, and an empty
     * slot is a to-do list.
     */
    it('never offers an ability Blob has not learned', () => {
        expect(names(companion({ abilities: [] }))).not.toContain('wave');
        expect(names(companion({ abilities: ['wave'] }))).not.toContain('jump');
    });

    it('ignores an ability with nothing to press', () => {
        // `carry` is a prop, not a pose: it draws, but it never plays.
        expect(names(companion({ abilities: ['carry'] }))).toEqual([
            'pet',
            'play',
        ]);
    });

    it('ignores `walk`, which is the ambient rather than a one-shot', () => {
        expect(names(companion({ abilities: ['walk'] }))).toEqual([
            'pet',
            'play',
        ]);
    });

    it('gives every action a label to put on its button', () => {
        for (const action of actionsFor(
            companion({ abilities: ['wave', 'jump'] }),
        )) {
            expect(action.label).toMatch(/^[A-Z][a-z]+$/);
        }
    });
});
