import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    __resetSpriteClock,
    __spriteClockIsRunning,
    useSpriteClock,
} from './use-sprite-clock';

/**
 * The clock is a module-level singleton on purpose, so these tests drive
 * requestAnimationFrame by hand rather than waiting on real frames. `pending`
 * holds whatever the loop has scheduled; `tick` runs exactly one frame.
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
    pending = [];
    handle = 0;
    reduceMotion(false);

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
    vi.unstubAllGlobals();
});

describe('useSpriteClock', () => {
    /**
     * The whole reason the loop lives at module scope. Two Blobs on one screen
     * — the corner instance and the big one — share a single frame budget.
     */
    it('runs exactly one rAF loop however many consumers there are', () => {
        renderHook(() => useSpriteClock('idle'));
        renderHook(() => useSpriteClock('idle'));
        renderHook(() => useSpriteClock('idle'));

        expect(pending).toHaveLength(1);

        tick(0);

        expect(pending).toHaveLength(1);
    });

    it('advances the ambient frame as time passes', () => {
        const { result } = renderHook(() => useSpriteClock('idle'));

        // idle is 2 frames at 2fps: half a second per frame.
        tick(0);
        expect(result.current.frame).toBe(0);

        tick(500);
        expect(result.current.frame).toBe(1);

        tick(1000);
        expect(result.current.frame).toBe(0);
    });

    /**
     * The ambient frame comes from the absolute timestamp, not from mount, so
     * two Blobs rendered seconds apart still breathe together.
     */
    it('keeps separate consumers in phase', () => {
        const first = renderHook(() => useSpriteClock('idle'));

        tick(500);

        const second = renderHook(() => useSpriteClock('idle'));

        tick(1500);

        expect(first.result.current.frame).toBe(1);
        expect(second.result.current.frame).toBe(1);
    });

    it('reads the ambient animation it was given', () => {
        const { result } = renderHook(() => useSpriteClock('walk'));

        tick(0);

        expect(result.current.animation).toBe('walk');
    });

    /**
     * A reaction wins while it runs, then hands the channel back — it does not
     * leave the ambient stopped behind it.
     */
    it('lets a reaction override the ambient and then reverts', () => {
        const { result } = renderHook(() => useSpriteClock('walk'));

        tick(0);
        expect(result.current.animation).toBe('walk');

        act(() => result.current.react('pet'));
        tick(1000);

        // pet is 4 frames at 8fps: half a second in total.
        expect(result.current.animation).toBe('pet');
        expect(result.current.frame).toBe(0);

        tick(1250);
        expect(result.current.animation).toBe('pet');
        expect(result.current.frame).toBe(2);

        tick(1600);
        expect(result.current.animation).toBe('walk');
    });

    it('restarts a reaction rather than queueing it', () => {
        const { result } = renderHook(() => useSpriteClock('idle'));

        act(() => result.current.react('play'));
        tick(0);
        tick(300);
        expect(result.current.frame).toBe(2);

        act(() => result.current.react('play'));
        tick(400);

        expect(result.current.animation).toBe('play');
        expect(result.current.frame).toBe(0);
    });

    it('stops the loop when the last consumer goes away', () => {
        const first = renderHook(() => useSpriteClock('idle'));
        const second = renderHook(() => useSpriteClock('idle'));

        expect(__spriteClockIsRunning()).toBe(true);

        first.unmount();
        expect(__spriteClockIsRunning()).toBe(true);

        second.unmount();
        expect(__spriteClockIsRunning()).toBe(false);
    });

    /** No reason to animate a drawing nobody is looking at. */
    it('stops while the tab is hidden and picks up again after', () => {
        renderHook(() => useSpriteClock('idle'));

        const hidden = vi.spyOn(document, 'hidden', 'get');

        hidden.mockReturnValue(true);
        act(() => document.dispatchEvent(new Event('visibilitychange')));
        expect(__spriteClockIsRunning()).toBe(false);

        hidden.mockReturnValue(false);
        act(() => document.dispatchEvent(new Event('visibilitychange')));
        expect(__spriteClockIsRunning()).toBe(true);

        hidden.mockRestore();
    });

    describe('with prefers-reduced-motion', () => {
        beforeEach(() => {
            reduceMotion(true);
        });

        it('holds frame 0 and never starts the loop', () => {
            const { result } = renderHook(() => useSpriteClock('idle'));

            expect(pending).toHaveLength(0);
            expect(__spriteClockIsRunning()).toBe(false);
            expect(result.current.frame).toBe(0);
        });

        /**
         * A button that does nothing is worse than a button that does not
         * animate, so the reaction still lands — as one step, held.
         */
        it('still applies a reaction, in a single step', () => {
            const { result } = renderHook(() => useSpriteClock('idle'));

            act(() => result.current.react('pet'));

            expect(result.current.animation).toBe('pet');
            expect(result.current.frame).toBe(1);
            expect(pending).toHaveLength(0);
        });
    });
});
