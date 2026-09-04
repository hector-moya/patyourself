/**
 * The animation registry. Data only — no logic lives here, and nothing here
 * imports anything.
 *
 * Adding an animation is an entry in this file plus one case in a renderer.
 * That is the whole contract: the clock reads `frames`/`fps`/`loop` and knows
 * nothing about what the frames look like, and the renderer reads
 * `(animation, frame)` and knows nothing about timing.
 *
 * Two channels. `ambient` loops forever and is what Blob does at rest.
 * `reaction` plays once, overrides the ambient for its duration, and hands back.
 * A reaction always wins, and a second reaction restarts rather than queues.
 *
 * Most of these are Blob's; the last two belong to the scene around it. They
 * are in the same table because a tree swaying at 3fps beside a Blob breathing
 * at 2fps is two rows here, not two frame loops.
 */
export type AnimationChannel = 'ambient' | 'reaction';

export interface AnimationSpec {
    /** How many frames the animation has. Frame indices run 0..frames-1. */
    frames: number;
    fps: number;
    /** Ambient animations loop; a reaction runs once and ends. */
    loop: boolean;
    channel: AnimationChannel;
    /**
     * Fire this one by itself, at a random interval in [min, max] ms. The
     * auto-timer in `use-sprite-clock.ts` schedules it for whichever of these
     * a given Blob has self-started for — `blink` always, everything else
     * only once earned.
     */
    autoEvery?: [number, number];
}

export const ANIMATIONS = {
    idle: { frames: 2, fps: 2, loop: true, channel: 'ambient' },
    blink: {
        frames: 2,
        fps: 8,
        loop: false,
        channel: 'ambient',
        autoEvery: [4000, 9000],
    },
    walk: { frames: 4, fps: 6, loop: true, channel: 'ambient' },
    wave: {
        frames: 4,
        fps: 8,
        loop: false,
        channel: 'ambient',
        autoEvery: [14000, 30000],
    },
    jump: {
        frames: 4,
        fps: 8,
        loop: false,
        channel: 'ambient',
        autoEvery: [20000, 45000],
    },
    pet: { frames: 4, fps: 8, loop: false, channel: 'reaction' },
    play: { frames: 6, fps: 8, loop: false, channel: 'reaction' },
    /**
     * An outcome has just been recorded. The `reaction` channel, because this
     * arrives from outside Blob rather than being something Blob does by
     * itself — and deliberately the smallest reaction there is: it lands in
     * the 32px corner while the user is mid-flow, and anything larger would
     * interrupt the one interaction this app protects most.
     */
    notice: { frames: 4, fps: 8, loop: false, channel: 'reaction' },
    /**
     * The scene's own, drawn by `companion-room.tsx` over a backdrop rather
     * than by a Blob renderer. Neither carries `autoEvery`: they are not
     * things Blob does, so the auto-timer has nothing to schedule here.
     *
     * Rates chosen by eye and written up in `scenes/README.md` — a four-second
     * cycle for a canopy and a two-second one for grass, so the two layers
     * disagree and read as air moving rather than as one shutter.
     */
    sway: { frames: 12, fps: 3, loop: true, channel: 'ambient' },
    rustle: { frames: 8, fps: 4, loop: true, channel: 'ambient' },
} as const satisfies Record<string, AnimationSpec>;

export type AnimationName = keyof typeof ANIMATIONS;

/** How long one pass of an animation takes, in milliseconds. */
export function durationOf(name: AnimationName): number {
    const spec: AnimationSpec = ANIMATIONS[name];

    return (spec.frames / spec.fps) * 1000;
}
