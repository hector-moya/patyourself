import { useCallback, useEffect, useRef, useState } from 'react';

import type { AnimationName } from '@/patyourself/companion-animations';
import { ANIMATIONS } from '@/patyourself/companion-animations';

/**
 * The frame clock behind Blob.
 *
 * ONE requestAnimationFrame loop for the whole app, no matter how many Blobs
 * are on screen. The loop lives at module scope and components subscribe to it;
 * a hook that started its own loop would mean the corner instance on Today and
 * the big one on /companion each burning their own frame budget, and drifting
 * out of phase with each other besides.
 *
 * The loop stops when the last subscriber leaves and whenever the tab is
 * hidden. There is no reason to animate a drawing nobody is looking at.
 */
type Tick = (now: number) => void;

const subscribers = new Set<Tick>();

let rafId: number | null = null;
let visibilityBound = false;

function loop(now: number): void {
    rafId = requestAnimationFrame(loop);

    // Copied before iterating: a subscriber that unsubscribes inside its own
    // tick would otherwise mutate the set mid-walk.
    for (const tick of [...subscribers]) {
        tick(now);
    }
}

function start(): void {
    if (rafId !== null || subscribers.size === 0) {
        return;
    }

    if (typeof document !== 'undefined' && document.hidden) {
        return;
    }

    rafId = requestAnimationFrame(loop);
}

function stop(): void {
    if (rafId === null) {
        return;
    }

    cancelAnimationFrame(rafId);
    rafId = null;
}

/** Bound once, on first subscribe, and never removed — the clock is a singleton. */
function bindVisibility(): void {
    if (visibilityBound || typeof document === 'undefined') {
        return;
    }

    visibilityBound = true;
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stop();
        } else {
            start();
        }
    });
}

function subscribe(tick: Tick): () => void {
    bindVisibility();
    subscribers.add(tick);
    start();

    return () => {
        subscribers.delete(tick);

        if (subscribers.size === 0) {
            stop();
        }
    };
}

/** Test seam: the module-level loop has to be resettable between tests. */
export function __resetSpriteClock(): void {
    stop();
    subscribers.clear();
}

/** Whether the loop is currently running. Exported for the "exactly one" test. */
export function __spriteClockIsRunning(): boolean {
    return rafId !== null;
}

function prefersReducedMotion(): boolean {
    if (typeof window === 'undefined' || !window.matchMedia) {
        return false;
    }

    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

export interface SpriteClock {
    /** What Blob is doing right now: the reaction if one is playing, else the ambient. */
    animation: AnimationName;
    frame: number;
    /** Fire a reaction. Restarts it if the same one is already playing. */
    react: (name: AnimationName) => void;
}

/**
 * Reads the shared clock and reports which frame of which animation to draw.
 *
 * The ambient frame is derived from the absolute timestamp rather than from
 * when this component mounted, so two Blobs on the same screen breathe in step
 * instead of a beat apart.
 *
 * `prefers-reduced-motion: reduce` holds frame 0 and never advances. A reaction
 * still lands — it applies its pose in a single step and stops there — because
 * a button that does nothing is worse than a button that does not animate.
 */
export function useSpriteClock(ambient: AnimationName = 'idle'): SpriteClock {
    const [frame, setFrame] = useState(0);
    const [reaction, setReaction] = useState<AnimationName | null>(null);

    // When the current reaction started, in clock time. Held in a ref because
    // it is written from inside the tick and must not itself cause a render.
    const reactionStartedAt = useRef<number | null>(null);

    const reduced = prefersReducedMotion();

    // Re-subscribed when the channel changes rather than reading the latest
    // values out of a ref: `ambient` changes when an ability is unlocked and
    // `reaction` twice per button press, so this costs nothing, and the tick
    // closing over exactly what it needs is far easier to follow.
    useEffect(() => {
        if (reduced) {
            return;
        }

        return subscribe((now: number) => {
            if (reaction !== null) {
                const spec = ANIMATIONS[reaction];

                // Stamped on the first tick after the trigger, not at the
                // trigger itself, so the reaction is timed against the same
                // clock that draws it.
                reactionStartedAt.current ??= now;

                const index = Math.floor(
                    ((now - reactionStartedAt.current) / 1000) * spec.fps,
                );

                if (index < spec.frames) {
                    setFrame(index);

                    return;
                }

                // Done. Hand the channel back to the ambient, which picks up
                // from the absolute clock rather than from frame 0 — the
                // ambient never stopped, it was only covered up.
                reactionStartedAt.current = null;
                setReaction(null);
            }

            const spec = ANIMATIONS[ambient];

            setFrame(Math.floor((now / 1000) * spec.fps) % spec.frames);
        });
    }, [ambient, reaction, reduced]);

    const react = useCallback(
        (name: AnimationName) => {
            // Restarts rather than queues: pressing Pet twice means "do it
            // again now", not "do it twice".
            reactionStartedAt.current = null;
            setReaction(name);

            // Under reduced motion there is no loop to advance the pose, so the
            // trigger is also the whole animation: one step, held. Frame 1 is
            // the pose every reaction opens with.
            setFrame(reduced ? Math.min(1, ANIMATIONS[name].frames - 1) : 0);
        },
        [reduced],
    );

    return {
        animation: reaction ?? ambient,
        // Reduced motion holds frame 0 unless a reaction put a pose there.
        // Derived rather than written into state by an effect, so nothing has
        // to run for the rule to hold.
        frame: reduced && reaction === null ? 0 : frame,
        react,
    };
}
