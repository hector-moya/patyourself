/**
 * Blob's animation registry. Data only — no logic lives here, and nothing here
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
     * Fire this one by itself, at a random interval in [min, max] ms. Read by
     * the auto-timer, which is not wired up yet.
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
    pet: { frames: 4, fps: 8, loop: false, channel: 'reaction' },
    play: { frames: 6, fps: 8, loop: false, channel: 'reaction' },
} as const satisfies Record<string, AnimationSpec>;

export type AnimationName = keyof typeof ANIMATIONS;

/** How long one pass of an animation takes, in milliseconds. */
export function durationOf(name: AnimationName): number {
    const spec: AnimationSpec = ANIMATIONS[name];

    return (spec.frames / spec.fps) * 1000;
}
