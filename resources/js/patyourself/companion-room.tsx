/**
 * Where Blob is drawn: the forest is always the world, and it is never
 * replaced. `forest` is a photographed backdrop swapped per part of day, with
 * the layers that move drawn over it — the one scene the record ever derives.
 *
 * The interior is a view of something Blob BUILT, not a second place the
 * record puts it in. Whether it is on screen is `inside`'s answer, held by
 * the client and never stored; which stage is drawn — lean-to, hut or cabin —
 * is `shelter`'s. Only the cabin has a window to look out of; a lean-to has
 * neither wall nor window, because it is open on every side but one.
 *
 * Both are a record of what happened. Indoors, only earned objects appear —
 * no greyed-out object, no silhouette, no empty slot. A room with two things
 * in it reads as a room; a room with two things and six grey outlines reads
 * as a task list.
 */
import type { ReactNode } from 'react';

import { useSpriteClock } from '@/hooks/use-sprite-clock';
import { BlobRenderer, FLOOR } from '@/patyourself/blob-renderer';
import { arrivingItem, describe } from '@/patyourself/companion';
import type { CompanionData } from '@/patyourself/companion';
import type { AnimationName } from '@/patyourself/companion-animations';
import { ANIMATIONS } from '@/patyourself/companion-animations';
import { partOfDay } from '@/patyourself/part-of-day';
import type { RoomPalette } from '@/patyourself/part-of-day';
import {
    CHEST_CELL,
    chestSprite,
    nodeCell,
    nodeSprite,
    paintOrder,
    sceneFor,
    SHELTER_CELL,
    shelterSprite,
} from '@/patyourself/scenes';
import type { FoliageSpec } from '@/patyourself/scenes';

/**
 * Wider and shorter than Blob's own box, and sharing its origin: x is measured
 * from the centre of the room, which is where Blob stands, and `FLOOR` is the
 * floor.
 */
export const ROOM = { x: -72, y: -38, w: 144, h: 114 };

/**
 * A point in the room's coordinates, as a CSS offset into the drawing's box.
 *
 * The scene is an `<svg>` with a viewBox, so it scales with its container and
 * absolute pixel offsets would only be right at one width. A relative offset
 * tracks it for free — which is what lets the node hotspots be real HTML buttons laid
 * over the picture rather than `<foreignObject>` inside it.
 *
 * Exported because the hotspots are drawn by the PAGE, not by this component:
 * this one returns the `<svg>` itself, and a button has to be a sibling of it
 * to be an ordinary focusable element in the document.
 */
export function roomOffset(
    x: number,
    y: number,
): { left: string; top: string } {
    return {
        left: `${((x - ROOM.x) / ROOM.w) * 100}%`,
        top: `${((y - ROOM.y) / ROOM.h) * 100}%`,
    };
}

/**
 * A box in the room's own units, as a CSS size over the drawing.
 *
 * The companion of `roomOffset` above, and exported for the same reason: the
 * hotspots are laid out by the PAGE, over an `<svg>` that scales with its
 * container, so their size has to be a share of that box rather than a pixel
 * count that is only right at one width.
 *
 * It replaces four hand-computed magic numbers — `.c-shelter--art` carried
 * `33.333%` and `42.105%`, which are 48/144 and 48/114 worked out by hand and
 * written down. Four node cells were about to add eight more.
 */
export function roomSize(
    width: number,
    height: number,
): { width: string; height: string } {
    return {
        width: `${(width / ROOM.w) * 100}%`,
        height: `${(height / ROOM.h) * 100}%`,
    };
}
const ROOM_VIEWBOX = `${ROOM.x} ${ROOM.y} ${ROOM.w} ${ROOM.h}`;

const INK = '#2A2622';

interface RoomObjectSpec {
    render: (palette: RoomPalette) => ReactNode;
}

/**
 * What can stand in the room, keyed by name — the same pattern as the item
 * dictionary, and read the same way: a `roomObject` the config names but this
 * dictionary does not know is skipped rather than drawn as a gap.
 *
 * Positions are fixed. An object arrives where it belongs and stays there;
 * furniture that rearranges itself would make the room feel less like a record
 * and more like a screensaver.
 */
const ROOM_OBJECTS: Record<string, RoomObjectSpec> = {
    bookshelf: {
        render: () => (
            <g className="room-object room-object--bookshelf">
                <rect
                    x={-64}
                    y={10}
                    width={30}
                    height={42}
                    rx={2}
                    fill="#8A6A4F"
                />
                <rect x={-61} y={19} width={24} height={2.5} fill="#6B5039" />
                <rect x={-61} y={33} width={24} height={2.5} fill="#6B5039" />
                {/* Books. Four of them, three colours — enough to read as books
                    at a glance and not so many that the shelf becomes busy. */}
                <rect x={-60} y={12} width={4} height={7} fill="#C25B4A" />
                <rect x={-55} y={13} width={4} height={6} fill="#5B8398" />
                <rect x={-50} y={12} width={5} height={7} fill="#D4942E" />
                <rect x={-60} y={26} width={4} height={7} fill="#5B8398" />
            </g>
        ),
    },

    // Wave's object: a rug flat on the floor, under Blob's own feet. Positioned
    // at x[-18, 18], clear of the bookshelf (ends at -34) on one side and of
    // everything on the right (which starts at 35) on the other.
    rug: {
        render: () => (
            <g className="room-object room-object--rug">
                <rect
                    x={-18}
                    y={49}
                    width={36}
                    height={3}
                    rx={1.5}
                    fill="#C6603F"
                />
                <rect x={-14} y={49.6} width={28} height={1} fill="#A64B30" />
            </g>
        ),
    },

    // Jump's object: a floor lamp against the right wall, past the window
    // (which ends at x=58) with room to spare before the room's own edge
    // (x=72). The shade tints to the part of the day — lit and warm once the
    // wall reads dark, muted otherwise. That is the one object in the room
    // that looks different at night, which is the point of handing it the
    // palette at all.
    lamp: {
        render: (palette) => {
            const lit = isDark(palette.wall);

            return (
                <g className="room-object room-object--lamp">
                    <rect
                        x={62}
                        y={49}
                        width={8}
                        height={3}
                        rx={1.5}
                        fill="#5B5850"
                    />
                    <rect x={65.5} y={6} width={1} height={43} fill="#5B5850" />
                    {lit && (
                        <circle
                            cx={66}
                            cy={-3}
                            r={5}
                            fill="#F2C572"
                            opacity={0.35}
                        />
                    )}
                    <path
                        d="M 62 6 L 70 6 L 68 -12 L 64 -12 Z"
                        fill={lit ? '#F2C572' : '#D8CBB0'}
                    />
                </g>
            );
        },
    },

    // Carry's object: a potted plant, clear of the stool on its right (starts
    // at 51) and of Blob's own footprint on its left (ends at 22).
    plant: {
        render: () => (
            <g className="room-object room-object--plant">
                <path
                    d="M 37 52 L 47 52 L 45.5 44 L 38.5 44 Z"
                    fill="#B5713F"
                />
                <circle cx={42} cy={35} r={7} fill="#5C8A52" />
                <circle cx={39} cy={32} r={4} fill="#6E9F5E" />
                <circle cx={45.5} cy={32} r={3.5} fill="#4C7A44" />
            </g>
        ),
    },

    // The tail's own object, with no authored rung: a stool between the plant
    // and the lamp.
    stool: {
        render: () => (
            <g className="room-object room-object--stool">
                <rect
                    x={51}
                    y={38}
                    width={9}
                    height={3}
                    rx={1.5}
                    fill="#A9835C"
                />
                <rect x={52.5} y={41} width={1.5} height={11} fill="#6B5039" />
                <rect x={58} y={41} width={1.5} height={11} fill="#6B5039" />
            </g>
        ),
    },
};

/**
 * Whether a wall colour reads as a lit room or a dark one, by relative
 * luminance rather than a fixed hour — so the lamp keyed off it still reads
 * correctly if the palette in config ever changes.
 */
function isDark(hex: string): boolean {
    const value = parseInt(hex.slice(1), 16);
    const r = (value >> 16) & 0xff;
    const g = (value >> 8) & 0xff;
    const b = value & 0xff;

    return (0.299 * r + 0.587 * g + 0.114 * b) / 255 < 0.5;
}

/**
 * One layer of moving foliage: a window cut over its sheet, moved along a row
 * of frames.
 *
 * A component of its own rather than a branch of the map that draws them,
 * because `useSpriteClock` is a hook and cannot be called from inside a loop.
 * Each layer therefore reads the clock once, and it is the same clock Blob
 * reads — one requestAnimationFrame for everything on the screen, which is the
 * whole reason a tree does not get to own a loop.
 *
 * Nothing self-starts here: the auto-timer fires what a Blob has unlocked, and
 * a tree has unlocked nothing.
 */
function FoliageLayer({ layer }: { layer: FoliageSpec }) {
    const { frame } = useSpriteClock(layer.animation, []);

    const [cellWidth, cellHeight] = layer.cell;
    const { frames } = ANIMATIONS[layer.animation];
    const index = (frame + (layer.phase ?? 0)) % frames;

    return (
        <svg
            className="scene-foliage"
            x={layer.at[0]}
            y={layer.at[1]}
            width={cellWidth}
            height={cellHeight}
            // One row, so the window only ever travels sideways.
            viewBox={`${index * cellWidth} 0 ${cellWidth} ${cellHeight}`}
        >
            <image
                href={layer.sheet}
                width={frames * cellWidth}
                height={cellHeight}
                style={{ imageRendering: 'pixelated' }}
            />
        </svg>
    );
}

/**
 * What Blob built, standing where the scene says it stands.
 *
 * A plain `<image>` rather than a `FoliageLayer`: the tree and the grass are
 * sheets read by the one shared clock because they exist to carry wind, and a
 * structure exists to be permanent. One frame, no clock, no phase.
 *
 * Drawn in the outdoor branch so it inherits the light wash. The sprites are
 * generated in neutral light, so a layer that escaped the overlay would stay
 * at noon all night — the same reason the foliage sits here.
 */
function ShelterLayer({
    stage,
    at,
}: {
    stage: string;
    at: readonly [number, number];
}) {
    const sprite = shelterSprite(stage);

    if (sprite === undefined) {
        return null;
    }

    return (
        <image
            data-shelter={stage}
            href={sprite}
            // `at` is the base centre, so the cell hangs up and left of it.
            x={at[0] - SHELTER_CELL / 2}
            y={at[1] - SHELTER_CELL}
            width={SHELTER_CELL}
            height={SHELTER_CELL}
            style={{ imageRendering: 'pixelated' }}
        />
    );
}

/**
 * One thing `paintOrder` can sort and this component can then draw: a node
 * at a band, or the chest. A discriminated union rather than two separate
 * `.map` calls, because the whole point of sorting them together is that
 * neither list is allowed to paint as a fixed block above or below the
 * other — see the paint-order comment where this is built, below.
 */
type PaintItem =
    | {
          kind: 'node';
          node: string;
          band: string;
          at: readonly [number, number];
      }
    | { kind: 'chest'; at: readonly [number, number] };

/**
 * The chest, when one has been built.
 *
 * One sprite and no bands. A node is drawn at the amount standing at it
 * because the amount is the thing you are deciding about; a chest is a
 * chest, and what is in it is in the bag, named exactly rather than banded.
 * Giving it bands would buy a picture the bag modal already gives precisely.
 *
 * A single frame and no clock, the same as the shelter: the tree and the
 * grass are sheets because this scene exists to carry wind, and a box does
 * not sway.
 */
function ChestLayer({ at }: { at: readonly [number, number] }) {
    return (
        <image
            data-chest="standing"
            href={chestSprite()}
            // `at` is the base centre, so the cell hangs up and left of it —
            // the same contract `SceneSpec.shelter` carries.
            x={at[0] - CHEST_CELL[0] / 2}
            y={at[1] - CHEST_CELL[1]}
            width={CHEST_CELL[0]}
            height={CHEST_CELL[1]}
            style={{ imageRendering: 'pixelated' }}
        />
    );
}

/**
 * What is standing at one node, at the amount it is standing in.
 *
 * The generalisation of `ShelterLayer` above, and a plain `<image>` for the
 * same reason: the tree and the grass are sheets read by the one shared clock
 * because they exist to carry wind, and a pile of timber does not sway. One
 * frame, no clock, no phase.
 *
 * `band` decides which of a node's sprites is drawn, and the band itself is
 * the server's answer — the thresholds are authored in
 * `config('companion.node_bands')` and are deliberately nowhere in this
 * language.
 */
function NodeLayer({
    node,
    band,
    at,
}: {
    node: string;
    band: string;
    at: readonly [number, number];
}) {
    const sprite = nodeSprite(node, band);
    const cell = nodeCell(node);

    if (sprite === undefined || cell === undefined) {
        return null;
    }

    const [width, height] = cell;

    return (
        <image
            data-node={node}
            data-band={band}
            href={sprite}
            // `at` is the base centre, so the cell hangs up and left of it —
            // the same contract `ShelterLayer` follows.
            x={at[0] - width / 2}
            y={at[1] - height}
            width={width}
            height={height}
            style={{ imageRendering: 'pixelated' }}
        />
    );
}

/**
 * How tall the pile is allowed to get, in room units. `FLOOR` sits at 52
 * (`BODY.h` 40 + `LEG_LENGTH` 12) and the bookshelf's own top is at y=10, so
 * at this height the pile's own top (`FLOOR - 26` = 26) stays short of it.
 *
 * A starting value, not a measured one: this is Task 9's to tune once it
 * renders in a browser, the same way `WOODPILE_K` below already says it is.
 */
export const WOODPILE_MAX_H = 26;

/**
 * How quickly it approaches that. Chosen by rendering, not derived: at 12 the
 * pile is half its ceiling by about a dozen units, which is roughly two
 * bagfuls, and still visibly growing at a hundred.
 */
const WOODPILE_K = 12;

/**
 * The drawn height of a pile holding `amount`.
 *
 * ASYMPTOTIC BY NECESSITY. The room is 114 units tall and the pile is
 * uncapped, so the picture cannot be linear in the amount — but it also must
 * not plateau, because a size it stops growing from reads as full, and a pile
 * that reads as full has rebuilt the end state this whole phase exists to
 * remove.
 *
 * `MAX_H * n / (n + K)` is monotonic non-decreasing, is strictly below
 * `MAX_H` for every finite n, and has no flat region.
 */
export function woodpileHeight(amount: number): number {
    return (WOODPILE_MAX_H * amount) / (amount + WOODPILE_K);
}

/**
 * Wood stacked against the wall, in the free gap between Blob's own
 * footprint (ends at x=22) and the plant's foliage (starts at x=35) —
 * x[22,35], 13 units wide. Of the room's five objects (bookshelf, rug, lamp,
 * plant, stool), only the plant borders this gap; the stool sits well clear
 * of it at x[51,60].
 *
 * NOT a `ROOM_OBJECT`, and not in the `room-object` class namespace either.
 * Those arrive from the ladder as gifts and are keyed by name; this one is
 * bought, and its size is a function of what was put into it rather than of
 * anything the record says. `room-shelter`/`room-shelter--lean-to` already
 * draws that same line for the shelter below, which is also built rather
 * than gifted — `room-woodpile` follows that precedent rather than
 * `room-object--woodpile`, so a selector scoped to earned objects (such as
 * `[class*="room-object--"]`) does not silently start counting a bought one.
 * No `--` modifier: unlike the shelter, the pile has no stages to select
 * between.
 *
 * `data-woodpile` carries the amount as a TEST SEAM, the same standing
 * `data-animation` and `data-part-of-day` already have. It is an attribute
 * inside a `role="img"`, not text: nothing on this screen renders what is in
 * the pile as a figure, because a figure of a holding is a total.
 */
function Woodpile({ amount }: { amount: number }) {
    const h = woodpileHeight(amount);
    const rows = Math.max(1, Math.round(h / 4));

    return (
        <g
            className="room-woodpile"
            data-woodpile={amount}
            data-woodpile-height={h}
        >
            <rect x={22} y={FLOOR - h} width={13} height={h} fill="#7A5B3A" />
            {Array.from({ length: rows }, (_, i) => (
                <rect
                    key={i}
                    x={23}
                    y={FLOOR - h + (i * h) / rows + 0.75}
                    width={11}
                    height={0.9}
                    fill="#5E442A"
                />
            ))}
        </g>
    );
}

export function CompanionRoom({
    companion,
    animation,
    frame,
    hour = new Date().getHours(),
    className = '',
    onPoke,
    inside = false,
    shelter = null,
    nodes = [],
    chestStanding = false,
    woodpile = 0,
}: {
    companion: CompanionData;
    animation: AnimationName;
    frame: number;
    /** Overridable so the room can be tested at a fixed time of day. */
    hour?: number;
    className?: string;
    /**
     * Fired when the scene itself is tapped, if the screen wants that.
     *
     * An affordance and never the only way in — reaching Blob by touching it
     * is the obvious gesture and the reason this exists, but the scene stays a
     * `role="img"`, so whatever the caller passes here must also be on a real
     * button somewhere on the screen. /companion's Poke is that button.
     */
    onPoke?: () => void;
    /**
     * Whether Blob is looking at the inside of what it built.
     *
     * Transient and never stored: where you are looking is not something you
     * own, and it resets to outside on load the way a modal does. Where Blob
     * IS and what Blob has BUILT are independent facts — you can stand outside
     * a cabin or sit inside a lean-to.
     */
    inside?: boolean;
    /**
     * Which stage is standing, when there is one. Null draws no interior.
     *
     * `'cabin'` is the default the scene override falls back to, so
     * `COMPANION_SCENE=cabin` still puts today's drawing on screen with
     * nothing built — which is the one way left to look at it.
     */
    shelter?: string | null;
    /**
     * What is standing at each node, by name and band. Placement is
     * `scenes.ts`'s; WHICH nodes are there and how much is at them is the
     * server's, exactly as `shelter` above is.
     *
     * Structurally satisfied by `BagNodeData`, so the page passes `bag.nodes`
     * straight through rather than mapping it into a new array on every
     * render.
     */
    nodes?: readonly { node: string; band: string }[];
    /**
     * Whether a chest is standing. The server's answer, exactly as `shelter`
     * and `nodes` are — a built chest is a bag row of category `structure`,
     * and nothing on this side reads the catalogue.
     */
    chestStanding?: boolean;
    /**
     * How much is in the pile. The server's answer, exactly as `nodes` and
     * `chestStanding` are — this component only turns it into a height.
     */
    woodpile?: number;
}) {
    if (!companion.features.includes('blob')) {
        return null;
    }

    const scene = sceneFor(companion.scene);

    // The scene override is the second way in, and the only one left that does
    // not require building something: `COMPANION_SCENE=cabin` still draws the
    // interior, which is what that development affordance exists for.
    const indoors = inside || scene.name === 'cabin';
    const stage = shelter ?? 'cabin';

    const part = partOfDay(hour, companion.room);
    const palette = companion.room[part] ?? {
        from: 0,
        wall: '#EFE6D6',
        window: '#B9D5E4',
        light: '#FFFFFF',
        dim: 0,
    };

    const objects = companion.room_objects
        .map((name) => [name, ROOM_OBJECTS[name]] as const)
        .filter(([, spec]) => spec !== undefined);

    return (
        <svg
            viewBox={ROOM_VIEWBOX}
            role="img"
            aria-label={`${describe(companion)}, ${indoors ? 'at home' : 'outside'}`}
            data-part-of-day={part}
            data-scene={scene.name}
            {...(indoors ? { 'data-interior': stage } : {})}
            onClick={onPoke}
            className={['blob-room', className].filter(Boolean).join(' ')}
        >
            {!indoors && (
                <>
                    {/* The colour behind the backdrop: a PNG that fails to
                        load leaves this in its place, so Blob never stands
                        on nothing. */}
                    <rect
                        x={ROOM.x}
                        y={ROOM.y}
                        width={ROOM.w}
                        height={ROOM.h}
                        fill={scene.base}
                    />
                    {scene.backdrops[part] && (
                        <image
                            className="scene-backdrop"
                            href={scene.backdrops[part]}
                            x={ROOM.x}
                            y={ROOM.y}
                            width={ROOM.w}
                            height={ROOM.h}
                            style={{ imageRendering: 'pixelated' }}
                        />
                    )}
                    {/* Over the backdrop, under Blob and under the wash: the
                        sheets are drawn in neutral light, so a layer that
                        escaped the overlay would stay at noon all night. */}
                    {scene.foliage.map((layer) => (
                        <FoliageLayer
                            key={`${layer.animation}-${layer.at[0]}-${layer.at[1]}`}
                            layer={layer}
                        />
                    ))}
                    {shelter !== null && scene.shelter !== undefined && (
                        <ShelterLayer stage={shelter} at={scene.shelter} />
                    )}
                    {/* After the shelter and over it: the building stands at
                        the treeline and the nodes and chest are nearer the
                        front, so painting them later is what puts them in
                        front.

                        Still under Blob and under the wash. The sprites are
                        drawn in neutral light, so a layer that escaped the
                        overlay would stay at noon all night.

                        NODES AND THE CHEST SHARE ONE PAINT ORDER, sorted by
                        base y rather than drawn as two separate loops in a
                        fixed sequence. The chest's own base y (73) falls
                        between the heap's (70) and the reeds' (76) — the
                        only two things it overlaps in x — so it has to paint
                        after the heap and before the reeds, not after every
                        node regardless of depth. `paintOrder` is a no-op for
                        the four nodes alone (`scenes.test.ts` pins that they
                        are already authored in this order); it only starts
                        doing work the moment a fifth thing — the chest —
                        joins the list. */}
                    {paintOrder<PaintItem>([
                        ...scene.nodes.flatMap((spec): PaintItem[] => {
                            const standing = nodes.find(
                                (candidate) => candidate.node === spec.node,
                            );

                            return standing === undefined
                                ? []
                                : [
                                      {
                                          kind: 'node',
                                          node: spec.node,
                                          band: standing.band,
                                          at: spec.at,
                                      },
                                  ];
                        }),
                        ...(chestStanding && scene.chest !== undefined
                            ? [{ kind: 'chest', at: scene.chest } as PaintItem]
                            : []),
                    ]).map((item) =>
                        item.kind === 'node' ? (
                            <NodeLayer
                                key={item.node}
                                node={item.node}
                                band={item.band}
                                at={item.at}
                            />
                        ) : (
                            <ChestLayer key="chest" at={item.at} />
                        ),
                    )}
                </>
            )}

            {indoors && (
                <>
                    {/* A lean-to is open on every side but one, so what is
                        behind Blob is the clearing rather than a wall. Drawn
                        from the scene's own base colour rather than a
                        literal, so it agrees with whatever the outside is. */}
                    {stage === 'lean-to' ? (
                        <rect
                            x={ROOM.x}
                            y={ROOM.y}
                            width={ROOM.w}
                            height={FLOOR - ROOM.y}
                            fill={scene.base}
                        />
                    ) : (
                        <rect
                            x={ROOM.x}
                            y={ROOM.y}
                            width={ROOM.w}
                            height={FLOOR - ROOM.y}
                            fill={palette.wall}
                        />
                    )}

                    {/* The floor is the same in all three: a floor is a
                        floor, and it is what stops Blob standing on nothing.
                        It is the wall colour under a flat shadow rather than
                        its own value, so a new time of day is still two
                        colours in config and not four. */}
                    <rect
                        x={ROOM.x}
                        y={FLOOR}
                        width={ROOM.w}
                        height={ROOM.y + ROOM.h - FLOOR}
                        fill={palette.wall}
                    />
                    <rect
                        x={ROOM.x}
                        y={FLOOR}
                        width={ROOM.w}
                        height={ROOM.y + ROOM.h - FLOOR}
                        fill={INK}
                        opacity={0.12}
                    />
                    <path
                        d={`M ${ROOM.x} ${FLOOR} H ${ROOM.x + ROOM.w}`}
                        stroke={INK}
                        strokeOpacity={0.35}
                        strokeWidth={1}
                    />

                    {/* The lean-to's own structure: one sloping beam on one
                        post, and nothing else. It reads as shelter because it
                        is over Blob's head, not because it encloses
                        anything. */}
                    {stage === 'lean-to' && (
                        <g
                            className="room-shelter room-shelter--lean-to"
                            data-room-shelter="lean-to"
                        >
                            {/* A path rather than a `<polygon>`: its own
                                attribute is a banned word's substring, which
                                CompanionVocabularyTest reads as a hit. */}
                            <path
                                d={`M ${ROOM.x} -32 L 44 4 L 44 11 L ${ROOM.x} -25 Z`}
                                fill="#7A5B3A"
                            />
                            <rect
                                x={41}
                                y={8}
                                width={3}
                                height={FLOOR - 8}
                                fill="#6B5039"
                            />
                        </g>
                    )}

                    {/* A hut has the gap a window will one day be, boarded
                        over. Same geometry as the cabin's window, so the
                        cabin reads as the same building with the boards
                        taken off. */}
                    {stage === 'hut' && (
                        <g data-window="shuttered">
                            <rect
                                x={16}
                                y={-22}
                                width={42}
                                height={30}
                                rx={2}
                                fill={palette.wall}
                            />
                            <path
                                d={`M 16 -12 H 58 M 16 -2 H 58`}
                                stroke={INK}
                                strokeOpacity={0.3}
                                strokeWidth={3}
                            />
                            <rect
                                x={16}
                                y={-22}
                                width={42}
                                height={30}
                                rx={2}
                                fill="none"
                                stroke={INK}
                                strokeOpacity={0.45}
                                strokeWidth={2}
                            />
                        </g>
                    )}

                    {/* The cabin's window, exactly as it has always been
                        drawn. This is the destination, not a thing being
                        replaced. */}
                    {stage === 'cabin' && (
                        <g data-window="open">
                            <rect
                                x={16}
                                y={-22}
                                width={42}
                                height={30}
                                rx={2}
                                fill={palette.window}
                            />
                            <path
                                d={`M 37 -22 V 8 M 16 -7 H 58`}
                                stroke={INK}
                                strokeOpacity={0.45}
                                strokeWidth={1.5}
                            />
                            <rect
                                x={16}
                                y={-22}
                                width={42}
                                height={30}
                                rx={2}
                                fill="none"
                                stroke={INK}
                                strokeOpacity={0.45}
                                strokeWidth={2}
                            />
                        </g>
                    )}

                    {/* Whatever the ladder handed over, drawn in whatever
                        exists. A bookshelf under a lean-to is funny rather
                        than wrong, and withholding it would mean the ladder
                        could hand over something invisible. */}
                    {objects.map(([name, spec]) => (
                        <g key={name} data-room-object={name}>
                            {spec.render(palette)}
                        </g>
                    ))}

                    {woodpile > 0 && <Woodpile amount={woodpile} />}
                </>
            )}

            <BlobRenderer
                renderer={companion.renderer}
                animation={animation}
                frame={frame}
                features={companion.features}
                items={companion.items}
                abilities={companion.abilities}
                arriving={arrivingItem(companion)}
            />

            {/* The part of day, laid over everything already drawn — the
                backdrop, whatever stands in front of it, and Blob. Blob is
                inside it rather than exempt from it because a creature lit
                differently from the world it is standing in reads as pasted
                on. Which parts need a wash is config's to say, not this
                file's: a part that carries no `dim` gets no rect, so midday
                tints nothing. */}
            {palette.dim ? (
                <rect
                    className="scene-light"
                    x={ROOM.x}
                    y={ROOM.y}
                    width={ROOM.w}
                    height={ROOM.h}
                    fill={palette.light}
                    opacity={palette.dim}
                    style={{ mixBlendMode: 'multiply' }}
                />
            ) : null}
        </svg>
    );
}
