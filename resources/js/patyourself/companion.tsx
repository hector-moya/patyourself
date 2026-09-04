/**
 * Blob, on its own.
 *
 * This file is the wrapper and nothing else: it runs the clock, puts a viewBox
 * around the drawing and hands the frame to a renderer. Everything about how
 * Blob looks lives in blob-renderer.tsx, and everything about when a frame
 * changes lives in use-sprite-clock.ts.
 *
 * Blob represents the work, not the user. Nothing here has a sad state, a
 * diminished state or a state that regresses: the range is neutral to warm, and
 * once a layer is on it stays on.
 *
 * The same component renders the 32px corner instance on Today and the big one
 * on /companion. Nothing is sized in pixels — the viewBox does the scaling.
 */
import { useEffect } from 'react';

import { useSpriteClock } from '@/hooks/use-sprite-clock';
import { BLOB_VIEWBOX, BlobRenderer } from '@/patyourself/blob-renderer';
import type { BlobItem } from '@/patyourself/blob-renderer';
import { ANIMATIONS } from '@/patyourself/companion-animations';
import type { AnimationName } from '@/patyourself/companion-animations';

export type CompanionItemData = BlobItem;

export interface CompanionUnlockData {
    kind: 'body' | 'item' | 'ability';
    name: string;
    variant: string | null;
    message: string;
    unlocked_at: string | null;
    /** What this unlock put in the room, if anything. */
    room_object: string | null;
}

export interface RoomPalette {
    /** The local hour this part of the day starts at. */
    from: number;
    wall: string;
    window: string;
    /** The colour the whole scene is washed with, Blob included. */
    light: string;
    /** How strongly. Zero at midday, when the light needs no help. */
    dim: number;
}

export interface CompanionData {
    log_count: number;
    insight_count: number;
    stage_index: number;
    features: string[];
    items: CompanionItemData[];
    abilities: string[];
    room_objects: string[];
    unlocks: CompanionUnlockData[];
    latest_unlock: CompanionUnlockData | null;
    /** Which renderer draws Blob, from config/companion.php. */
    renderer: string;
    /**
     * What each part of the day is drawn in, from config — the cabin's wall
     * and window, and the light the whole scene is washed with. See
     * `RoomPalette` above for what a part carries.
     */
    room: Record<string, RoomPalette>;
    /**
     * Which of Blob's two places the record puts it in — `'forest'` or
     * `'cabin'`, derived server-side from the same counts that walk the
     * ladder. See `sceneFor` in `scenes.ts` for what an unrecognised value
     * falls back to.
     */
    scene: string;
}

/** The height of the standalone drawing, relative to its width. */
const ASPECT = 84 / 64;

/**
 * What Blob does at rest. Walking is the ambient once Blob can walk, and idle
 * before that — they are the same channel, so only one of them ever runs.
 */
export function ambientFor(companion: CompanionData): AnimationName {
    return companion.abilities.includes('walk') ? 'walk' : 'idle';
}

/**
 * Which self-starting animations this Blob is allowed to fire.
 *
 * `blink` always: it is not an ability, it is being alive. Everything else has
 * to have been earned, or the body would be doing things the ladder has not
 * announced yet.
 */
export function selfStartedFor(companion: CompanionData): AnimationName[] {
    const earned = companion.abilities.filter(
        (ability): ability is AnimationName =>
            ability in ANIMATIONS &&
            ANIMATIONS[ability as AnimationName].channel === 'ambient' &&
            'autoEvery' in ANIMATIONS[ability as AnimationName],
    );

    return ['blink', ...earned];
}

/**
 * Renders nothing until Blob exists. Before the first outcome there is no
 * placeholder, no outline and no "unlocks soon" — an empty slot is a to-do, and
 * Blob is not one.
 */
export function Companion({
    companion,
    size = 120,
    className = '',
    reactTo = null,
}: {
    companion: CompanionData;
    size?: number;
    className?: string;
    /**
     * The id of an outcome recorded on the request that rendered this page,
     * or null. Blob reacts once each time it changes.
     *
     * An id rather than a flag: two outcomes logged one after the other both
     * deserve a reaction, and a boolean that is already `true` never changes.
     */
    reactTo?: number | null;
}) {
    // Called before the early return: a Blob that does not exist yet still has
    // to obey the rules of hooks.
    const { animation, frame, react, reduced } = useSpriteClock(
        ambientFor(companion),
        selfStartedFor(companion),
    );

    // Unlike Pet and Play, nobody pressed anything for this one — it fires the
    // moment an outcome is recorded, in the corner of the most-visited screen.
    // Under reduced motion there is no loop left to run the one-shot back down
    // once it lands (see use-sprite-clock's effect), so calling `react` here
    // would leave Blob stuck in the noticed pose for good. The design's rule
    // for this reaction is movement only, and a permanently held pose is
    // neither movement nor the neutral rest state reduced motion asks for —
    // so an unprompted reaction is skipped outright rather than fired and
    // left stranded.
    useEffect(() => {
        if (reactTo !== null && !reduced) {
            react('notice');
        }
    }, [reactTo, react, reduced]);

    if (!companion.features.includes('blob')) {
        return null;
    }

    return (
        <svg
            viewBox={BLOB_VIEWBOX}
            width={size}
            height={size * ASPECT}
            role="img"
            aria-label={describe(companion)}
            className={['blob', className].filter(Boolean).join(' ')}
        >
            <BlobRenderer
                renderer={companion.renderer}
                animation={animation}
                frame={frame}
                features={companion.features}
                items={companion.items}
                abilities={companion.abilities}
                arriving={arrivingItem(companion)}
            />
        </svg>
    );
}

/**
 * The item Blob earned most recently, or null when the last thing it earned was
 * not something it wears. That layer fades in once; everything already on Blob
 * is simply there.
 */
export function arrivingItem(
    companion: CompanionData,
): CompanionItemData | null {
    const latest = companion.latest_unlock;

    return latest === null || latest.kind !== 'item'
        ? null
        : { type: latest.name, variant: latest.variant };
}

/**
 * What a screen reader is told. States what Blob has, in the same register as
 * the copy: a description, never a score.
 */
export function describe(companion: CompanionData): string {
    const worn = companion.items.map((item) =>
        item.variant === null ? item.type : `${item.variant} ${item.type}`,
    );
    const parts = [...worn, ...companion.abilities];

    return parts.length === 0 ? 'Blob' : `Blob, with ${parts.join(', ')}`;
}
