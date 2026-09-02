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
    /**
     * Whether `prefers-reduced-motion: reduce` is active.
     *
     * Exposed so a caller can tell an *unprompted* reaction — one the user did
     * not ask for by pressing a button — apart from one they did. `react()`
     * still applies a held pose either way (a button that does nothing is
     * worse than a button that does not animate), but a reaction fired at a
     * user who never acted has nothing to justify freezing their screen, and
     * this is what lets the caller skip it instead.
     */
    reduced: boolean;
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
export function useSpriteClock(
    ambient: AnimationName = 'idle',
    selfStarted: readonly AnimationName[] = ['blink'],
): SpriteClock {
    const [frame, setFrame] = useState(0);
    const [reaction, setReaction] = useState<AnimationName | null>(null);

    // A one-shot on the ambient channel — a blink. Held apart from `reaction`
    // so a button press can cut across a blink without the two clearing each
    // other's state on the way out.
    const [ambientOneShot, setAmbientOneShot] = useState<AnimationName | null>(
        null,
    );

    // When the current one-shot started, in clock time. A ref because it is
    // written from inside the tick and must not itself cause a render.
    const startedAt = useRef<number | null>(null);

    const reduced = prefersReducedMotion();

    // A reaction always wins. A blink covers the ambient loop but yields to
    // anything the user did.
    const playing = reaction ?? ambientOneShot;

    // Re-subscribed when the channel changes rather than reading the latest
    // values out of a ref: `ambient` changes when an ability is unlocked and
    // `playing` twice per one-shot, so this costs nothing, and the tick closing
    // over exactly what it needs is far easier to follow.
    useEffect(() => {
        if (reduced) {
            return;
        }

        return subscribe((now: number) => {
            if (playing !== null) {
                const spec = ANIMATIONS[playing];

                // Stamped on the first tick after the trigger, not at the
                // trigger itself, so the one-shot is timed against the same
                // clock that draws it.
                startedAt.current ??= now;

                const index = Math.floor(
                    ((now - startedAt.current) / 1000) * spec.fps,
                );

                if (index < spec.frames) {
                    setFrame(index);

                    return;
                }

                // Done. Hand the channel back to the ambient, which picks up
                // from the absolute clock rather than from frame 0 — the
                // ambient never stopped, it was only covered up.
                startedAt.current = null;
                setReaction(null);
                setAmbientOneShot(null);
            }

            const spec = ANIMATIONS[ambient];

            setFrame(Math.floor((now / 1000) * spec.fps) % spec.frames);
        });
    }, [ambient, playing, reduced]);

    // A joined string rather than the array itself: `selfStarted` is a fresh
    // array on every render for a caller that builds it inline (the selector
    // is called fresh each time), and depending on its identity would tear
    // down and restart every pending timer on every render. The content is
    // what matters, and animation names never contain a comma.
    const selfStartedKey = selfStarted.join(',');

    /**
     * The auto-timer. Every ambient animation carrying `autoEvery` fires itself
     * on a random interval inside that range — a blink you could set a watch by
     * would read as a machine rather than as a thing that is alive.
     *
     * Only what this Blob has actually unlocked gets scheduled. Without that
     * gate, a Blob that cannot wave would wave anyway — the body doing
     * something the ladder has not announced yet.
     *
     * Nothing schedules under reduced motion: an unprompted animation is
     * exactly what that preference is asking not to happen.
     */
    useEffect(() => {
        if (reduced) {
            return;
        }

        const timers: number[] = [];

        const schedule = (
            name: AnimationName,
            spread: readonly [number, number],
        ) => {
            const [min, max] = spread;

            timers.push(
                window.setTimeout(
                    () => {
                        startedAt.current = null;
                        setAmbientOneShot(name);
                        schedule(name, spread);
                    },
                    min + Math.random() * (max - min),
                ),
            );
        };

        for (const [name, spec] of Object.entries(ANIMATIONS)) {
            if (
                spec.channel === 'ambient' &&
                'autoEvery' in spec &&
                selfStarted.includes(name as AnimationName)
            ) {
                schedule(name as AnimationName, spec.autoEvery);
            }
        }

        return () => timers.forEach((timer) => window.clearTimeout(timer));

        // `selfStarted` drives this effect through `selfStartedKey` above —
        // its content, not its identity, is what should restart the timers.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [reduced, selfStartedKey]);

    const react = useCallback(
        (name: AnimationName) => {
            // Restarts rather than queues: pressing Pet twice means "do it
            // again now", not "do it twice".
            startedAt.current = null;
            setReaction(name);

            // Under reduced motion there is no loop to advance the pose, so the
            // trigger is also the whole animation: one step, held. Frame 1 is
            // the pose every reaction opens with.
            setFrame(reduced ? Math.min(1, ANIMATIONS[name].frames - 1) : 0);
        },
        [reduced],
    );

    return {
        animation: playing ?? ambient,
        // Reduced motion holds frame 0 unless a reaction put a pose there.
        // Derived rather than written into state by an effect, so nothing has
        // to run for the rule to hold.
        frame: reduced && reaction === null ? 0 : frame,
        react,
        reduced,
    };
}
