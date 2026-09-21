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
import { asleepAt, wakingAt } from '@/patyourself/part-of-day';
import type { RoomPalette } from '@/patyourself/part-of-day';

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

/** One stack Blob is carrying. */
export interface BagItemData {
    item: string;
    label: string;
    category: string;
    quantity: number;
    /**
     * Whether the bag can be relieved of it. Answered by the server from
     * `capacity.carried` rather than derived from `category` here, so what
     * Blob carries has one author — a tool is on the belt and a container is
     * the room itself.
     */
    droppable: boolean;
}

/** A node Blob has met, and what is standing at it. */
export interface BagNodeData {
    node: string;
    label: string;
    available: number;
    /**
     * The skill that unlocks it, or null for a heap — something that was put
     * in the clearing rather than something Blob learns to use. Null is the
     * one falsy case, the same choice `BagRecipeData.tool` made.
     */
    skill: string | null;
    /** Whether Blob has walked over and looked at it. */
    met: boolean;
    /** Whether the skill that unlocks it has been bought, or there is none. */
    known: boolean;
    /**
     * Whether the gesture would actually do something right now — `known`
     * AND either the node names no tool or that tool is held. Differs from
     * `known` exactly when a node names a tool Blob is not carrying yet.
     */
    usable: boolean;
    /**
     * Which band the amount falls in — what the clearing actually draws, since
     * the art carries the amount and no number is printed out there.
     *
     * A plain string, not a union of the three names. The bands are authored
     * in `config('companion.node_bands')`, and a union here would be a second
     * declaration of which bands exist, in a language that cannot read the
     * first. `nodeSprite()` resolves a name it does not know to `undefined`
     * and draws nothing, which is the same contract every other registry in
     * this feature follows.
     */
    band: string;
}

/**
 * A skill whose node Blob has met.
 *
 * `affordable` rather than a flag meaning "hide this": an unaffordable skill
 * stays on the list WITH its price, and it is the button that is disabled. A
 * price you cannot pay yet is a menu; a greyed row is a lock.
 */
export interface BagSkillData {
    skill: string;
    label: string;
    price: number;
    known: boolean;
    affordable: boolean;
}

/** Something buildable out of materials Blob has met. */
export interface BagRecipeData {
    item: string;
    label: string;
    recipe: Record<string, number>;
    /**
     * What has to be in hand, or null. Part of the PRICE rather than a
     * requirement — a tool is the same kind of statement as `4 fibre`, which
     * has named something Blob may not have since F1.
     */
    tool: string | null;
    buildable: boolean;
}

/**
 * The shelter: what is standing, and the one stage that can be chosen now.
 *
 * `offer` is null far more often than not — before planks are accountable,
 * once the arc is finished, and whenever the record has not reached a stage's
 * floor. A null offer renders NOTHING, not a placeholder and not an
 * explanation: a stage you cannot reach is absent, the same way an unmet
 * skill is.
 */
export interface BagShelterData {
    built: string | null;
    label: string | null;
    offer: {
        stage: string;
        label: string;
        recipe: Record<string, number>;
        buildable: boolean;
    } | null;
}

/**
 * The chosen half of Blob: what has been spent, bought, gathered and built.
 *
 * Assembled server-side by `CompanionBag`. Note what is NOT here — no count of
 * skills that exist, no count of nodes that exist, no share of a whole, and no
 * "next". The absence is the design, and it is guarded on both sides.
 */
export interface CompanionBagData {
    /** The balance, and nothing about a target. */
    xp: number;
    capacity: number;
    held: number;
    name: string;
    items: BagItemData[];
    nodes: BagNodeData[];
    skills: BagSkillData[];
    recipes: BagRecipeData[];
    shelter: BagShelterData;
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
     * `RoomPalette` in `part-of-day.ts` for what a part carries.
     */
    room: Record<string, RoomPalette>;
    /**
     * Which of Blob's two places the record puts it in — `'forest'` or
     * `'cabin'`, derived server-side from the same counts that walk the
     * ladder. See `sceneFor` in `scenes.ts` for what an unrecognised value
     * falls back to.
     */
    scene: string;
    /**
     * What the user calls their companion; `'Blob'` until they say otherwise.
     *
     * Carried so the client can name it in copy IT writes — `describe()`'s
     * aria-label, and anything a screen composes itself. Every message in
     * `unlocks` already has the name substituted in server-side, so nothing
     * here should ever be doing that substitution a second time.
     */
    name: string;
}

/** The height of the standalone drawing, relative to its width. */
const ASPECT = 84 / 64;

/**
 * What Blob does at rest.
 *
 * A precedence list of two rungs, and the order is a claim about the creature
 * rather than a priority number: sleeping is a state of the whole of Blob,
 * where walking and idling are what an awake Blob does. One return value, so
 * only one ambient can ever run — the same reason `walk` and `idle` have
 * always shared a channel.
 *
 * The hour defaults to the browser's, matching the room's light, which is
 * deliberate: the two must never split. A sleeping Blob in a midday room is a
 * bug you can see, and two clocks is how you get one. Overridable so a test
 * can pin the time of day, exactly as `CompanionRoom` already allows.
 *
 * Nothing here reads the record. Blob's life is never a mirror: it sleeps
 * because it is night, never because of anything the person did or did not do.
 */
export function ambientFor(
    companion: CompanionData,
    hour: number = new Date().getHours(),
): AnimationName {
    if (asleepAt(hour, companion.room)) {
        return 'sleep';
    }

    return companion.abilities.includes('walk') ? 'walk' : 'idle';
}

/**
 * Which self-starting animations this Blob is allowed to fire.
 *
 * `blink` and `look` always: they are not abilities, they are being alive.
 * Everything else has to have been earned, or the body would be doing things
 * the ladder has not announced yet.
 *
 * Nothing at all while Blob is asleep. `blink` has a row on every form, so
 * leaving it scheduled would open a sleeping Blob's eyes for an eighth of a
 * second every few seconds.
 *
 * `stretch` is the morning's, and only the morning's. There is no waking
 * moment to hang it on — see `wakingAt` — so it fires on the same random
 * interval as everything else here, a few times through the part of the day
 * that follows sleeping.
 */
export function selfStartedFor(
    companion: CompanionData,
    hour: number = new Date().getHours(),
): AnimationName[] {
    if (asleepAt(hour, companion.room)) {
        return [];
    }

    const earned = companion.abilities.filter(
        (ability): ability is AnimationName =>
            ability in ANIMATIONS &&
            ANIMATIONS[ability as AnimationName].channel === 'ambient' &&
            'autoEvery' in ANIMATIONS[ability as AnimationName],
    );

    const alive: AnimationName[] = wakingAt(hour, companion.room)
        ? ['blink', 'look', 'stretch']
        : ['blink', 'look'];

    return [...alive, ...earned];
}

/** One button in the row under the scene: an animation and the word on it. */
export interface CompanionAction {
    animation: AnimationName;
    label: string;
}

/**
 * What can be asked of Blob, and when.
 *
 * Two kinds, and the difference is who is acting. `pet` and `play` are things
 * done *to* Blob and have always been there, so they need nothing earned.
 * Everything else asks Blob to use an ability it had to learn, and appears
 * only once the ladder has announced it.
 *
 * An ability Blob has not learned is ABSENT rather than disabled. A greyed
 * button is an empty slot, an empty slot is a preview of what is coming, and
 * this screen only ever shows what has happened.
 *
 * `walk` is deliberately not here: it is the ambient rather than a one-shot
 * (see `ambientFor`), and a button that starts an endless loop has nothing to
 * hand back to.
 */
const ACTIONS: readonly (CompanionAction & { earned: boolean })[] = [
    { animation: 'pet', label: 'Pet', earned: false },
    { animation: 'play', label: 'Play', earned: false },
    { animation: 'wave', label: 'Wave', earned: true },
    { animation: 'jump', label: 'Jump', earned: true },
];

export function actionsFor(companion: CompanionData): CompanionAction[] {
    return ACTIONS.filter(
        (action) =>
            !action.earned || companion.abilities.includes(action.animation),
    ).map(({ animation, label }) => ({ animation, label }));
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
 * What a screen reader is told. States what the companion has, in the same
 * register as the copy: a description, never a score.
 *
 * The name comes off the payload rather than being written here. Every message
 * the server sends already has it substituted in; this is the one place the
 * CLIENT writes a sentence about the companion, so it is the one place that
 * needs the name itself.
 */
export function describe(companion: CompanionData): string {
    const name = companion.name.trim() === '' ? 'Blob' : companion.name;
    const worn = companion.items.map((item) =>
        item.variant === null ? item.type : `${item.variant} ${item.type}`,
    );
    const parts = [...worn, ...companion.abilities];

    return parts.length === 0 ? name : `${name}, with ${parts.join(', ')}`;
}
