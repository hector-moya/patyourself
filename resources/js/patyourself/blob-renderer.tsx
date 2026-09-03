/**
 * How Blob gets drawn.
 *
 * The split exists so the drawing can be replaced without touching the clock,
 * the ladder, the room or anything that reads them. A renderer is handed
 * `(animation, frame)` plus what Blob owns, and returns a `<g>`. It does not
 * know what time it is, what a second is, or why the frame changed.
 *
 * A renderer returns a group rather than a whole `<svg>` because Blob is drawn
 * both on its own and standing inside the room, and those two want different
 * viewBoxes around the same body.
 */
import type { CSSProperties, ReactNode } from 'react';

import type { AnimationName } from '@/patyourself/companion-animations';
import { SPRITE_ITEMS } from '@/patyourself/sprite-items';
import type { PaletteKey } from '@/patyourself/sprite-items';
import {
    anchorFor,
    CELL,
    columnsOf,
    formFor,
    rowFor,
} from '@/patyourself/sprite-layout';

/** The body box, in viewBox units. x is measured from Blob's centre line. */
export const BODY = { w: 44, h: 40, rx: 13 };

/**
 * Where things attach. Anything added later hangs off one of these four rather
 * than off the body's own geometry.
 *
 * `feet` is the body's underside, not the ground: legs hang from it, and
 * anything worn on the feet sits `LEG_LENGTH` further down.
 */
export const ANCHOR = {
    head: [0, 0],
    neck: [0, 31],
    feet: [0, BODY.h],
    hand: [26, 34],
} as const;

export const LEG_LENGTH = 12;

/** Where Blob's feet meet the ground, in its own coordinates. */
export const FLOOR = BODY.h + LEG_LENGTH;

/** The viewBox a lone Blob is drawn in. The room supplies its own. */
export const BLOB_VIEWBOX = '-32 -22 64 84';

/**
 * Blob's own colour, as a literal rather than a theme token: Blob looks like
 * itself in light mode and in dark mode, and against a day wall or a night one.
 * Accessories are free to vary; the body is not, because a Blob that changes
 * colour with its surroundings is a different Blob.
 */
const BODY_COLOUR = '#7A9E7E';
const INK = '#2A2622';

/**
 * Accessory colours, and the variants a recolour can name.
 *
 * Every name in `config('companion.tail.variants')` has to be a key here —
 * CompanionTailPaletteTest guards it — or `Worn()` silently falls back to the
 * item's own default colour instead of applying the recolour the ladder's
 * message just announced.
 */
export const PALETTE: Record<string, string> = {
    slate: '#3E4A55',
    rust: '#C25B4A',
    coral: '#E8836B',
    moss: '#6E8F5A',
    amber: '#D4942E',
    plum: '#8C5B7A',
    sand: '#C9A66B',
};

type AnchorName = keyof typeof ANCHOR;

interface ItemSpec {
    anchor: AnchorName;
    /** The item's own colour when the unlock names no variant. */
    colour: string;
    render: (colour: string) => ReactNode;
}

/**
 * What Blob wears. Four types, forever — past the fourth the ladder recolours
 * one of these rather than adding a fifth, so this dictionary does not grow.
 */
const ITEMS: Record<string, ItemSpec> = {
    shoes: {
        anchor: 'feet',
        colour: PALETTE.slate,
        render: (colour) => (
            <>
                <rect
                    x={-13}
                    y={LEG_LENGTH - 3}
                    width={11}
                    height={6}
                    rx={3}
                    fill={colour}
                />
                <rect
                    x={2}
                    y={LEG_LENGTH - 3}
                    width={11}
                    height={6}
                    rx={3}
                    fill={colour}
                />
            </>
        ),
    },
    scarf: {
        anchor: 'neck',
        colour: PALETTE.rust,
        render: (colour) => (
            <>
                <rect
                    x={-22}
                    y={0}
                    width={44}
                    height={6}
                    rx={3}
                    fill={colour}
                />
                <rect x={10} y={4} width={6} height={13} rx={3} fill={colour} />
            </>
        ),
    },
    hat: {
        anchor: 'head',
        colour: PALETTE.slate,
        render: (colour) => (
            <>
                <rect
                    x={-11}
                    y={-15}
                    width={22}
                    height={14}
                    rx={4}
                    fill={colour}
                />
                <rect
                    x={-18}
                    y={-3}
                    width={36}
                    height={5}
                    rx={2.5}
                    fill={colour}
                />
            </>
        ),
    },
    glasses: {
        anchor: 'head',
        colour: INK,
        render: (colour) => (
            <g fill="none" stroke={colour} strokeWidth={2}>
                <circle cx={-8} cy={17} r={7} />
                <circle cx={8} cy={17} r={7} />
                <path d="M -1 17 L 1 17" />
            </g>
        ),
    },
};

interface AbilitySpec {
    /** Anything the pose needs drawn that the body does not already have. */
    extra?: (colour: string) => ReactNode;
}

/**
 * What Blob can do. Unbounded — the interesting track. An ability with no entry
 * here needs nothing drawn beyond the body, which is why the ladder can name
 * `wave` long before anyone poses it.
 *
 * Both entries below are positioned relative to `ANCHOR.hand`, the only anchor
 * an ability can draw from. Coordinates are chosen against the real `BODY`
 * (44 wide, 40 tall) and `ANCHOR.hand` ([26, 34], just outside the body's right
 * edge) rather than a guessed body span, so the props sit against Blob instead
 * of floating off it or bleeding past the 64-wide viewBox the dashboard corner
 * draws at 32px.
 */
const ABILITIES: Record<string, AbilitySpec> = {
    /**
     * A book, held low against Blob's right side (its own left, since the hand
     * anchor sits on the viewer's right). What Blob reads is unclear, but it
     * holds the page the right way up — the ladder's words, so the drawing has
     * to agree.
     */
    read: {
        extra: () => (
            <g className="blob-ability blob-ability--read">
                {/* Two leaves and a spine between them. Rendered in the room,
                    the scarf's default colour (PALETTE.rust) sits at this
                    same hand anchor's original height, and its hanging tail
                    reaches y=14 in this frame (y=48 absolute) — so the book
                    sits below both the scarf's main band and that tail, and
                    is coloured outside the red family entirely (the slate
                    blue the bookshelf's own books already use) so the two
                    never read as one shape even where the boxes come close. */}
                <rect
                    x={-10}
                    y={6}
                    width={8}
                    height={8}
                    rx={1}
                    fill="#5B8398"
                />
                <rect x={-2} y={6} width={8} height={8} rx={1} fill="#7FA3B5" />
                <rect x={-2.5} y={6} width={1} height={8} fill={INK} />
            </g>
        ),
    },

    /**
     * Something to carry, stacked above the book rather than mirrored beside
     * it — the viewBox leaves little room to the right of the hand anchor, and
     * stacking still keeps a Blob that both reads and carries from looking
     * like it is holding two things in one spot. The ladder says Blob "has not
     * settled on what", so this stays a plain shape rather than a recognisable
     * object.
     */
    carry: {
        extra: () => (
            <g className="blob-ability blob-ability--carry">
                <rect
                    x={-4}
                    y={-14}
                    width={8}
                    height={8}
                    rx={2}
                    fill={PALETTE.amber}
                />
            </g>
        ),
    },
};

export interface BlobItem {
    type: string;
    variant: string | null;
}

export interface BlobRendererProps {
    animation: AnimationName;
    frame: number;
    /** Body parts earned: `blob`, then `legs`. */
    features: string[];
    items: BlobItem[];
    abilities: string[];
    /**
     * The item earned most recently, which fades in once on mount. The only
     * transition in the feature that is not the frame clock's doing.
     */
    arriving?: BlobItem | null;
}

/**
 * Picks the implementation. The flag comes from config/companion.php and rides
 * along with the companion payload, so switching Blob over to sprites is a
 * deploy rather than a release.
 */
export function BlobRenderer({
    renderer = 'svg',
    ...props
}: BlobRendererProps & { renderer?: string }) {
    return renderer === 'sprite' ? (
        <SpriteBlobRenderer {...props} />
    ) : (
        <SvgBlobRenderer {...props} />
    );
}

/**
 * Blob as flat geometry. Every animation resolves to one transform on one
 * group, plus — for the animations that close Blob's eyes — a different pair of
 * eyes. Accessories sit inside the transformed group, so they follow the body
 * instead of sliding off it.
 */
export function SvgBlobRenderer({
    animation,
    frame,
    features,
    items,
    abilities,
    arriving = null,
}: BlobRendererProps) {
    const hasLegs = features.includes('legs');
    const extras = abilities
        .map((ability) => ABILITIES[ability])
        .filter((pose): pose is AbilitySpec => pose !== undefined);

    return (
        <g
            className="blob-anim"
            data-animation={animation}
            data-frame={frame}
            style={bodyTransform(animation, frame, hasLegs)}
        >
            {hasLegs && (
                <g className="blob-legs" fill={BODY_COLOUR}>
                    <rect
                        className="blob-legs__left"
                        x={-11}
                        y={BODY.h - 4}
                        width={7}
                        height={LEG_LENGTH + 4}
                        rx={3.5}
                        style={legTransform(animation, frame, 1)}
                    />
                    <rect
                        className="blob-legs__right"
                        x={4}
                        y={BODY.h - 4}
                        width={7}
                        height={LEG_LENGTH + 4}
                        rx={3.5}
                        style={legTransform(animation, frame, -1)}
                    />
                </g>
            )}

            <g className="blob-body">
                <rect
                    x={-BODY.w / 2}
                    y={0}
                    width={BODY.w}
                    height={BODY.h}
                    rx={BODY.rx}
                    fill={BODY_COLOUR}
                />
                <Eyes closed={eyesClosed(animation, frame)} />
            </g>

            {items.map((item, index) => (
                <Worn
                    key={`${item.type}-${item.variant ?? 'plain'}-${index}`}
                    item={item}
                    arriving={
                        arriving !== null &&
                        arriving.type === item.type &&
                        arriving.variant === item.variant
                    }
                />
            ))}

            {extras.map((pose, index) =>
                pose.extra === undefined ? null : (
                    <g
                        key={`pose-${index}`}
                        transform={translate(ANCHOR.hand)}
                        className="blob-layer"
                    >
                        {pose.extra(BODY_COLOUR)}
                    </g>
                ),
            )}
        </g>
    );
}

/**
 * `SPRITE_ITEMS` names its default colour by PALETTE *key* (`PaletteKey`, a
 * literal union, not a plain string) rather than by value, so that module
 * never has to import `PALETTE` to read it — see its own docblock for why
 * that import would be a cycle. This is where the key is resolved back into
 * a colour, alongside `INK` for glasses, whose default isn't a
 * tail-recolourable accessory shade at all.
 *
 * Typed as `Record<PaletteKey | 'ink', string>` rather than `Record<string,
 * string>` on purpose: every branch `spec.colourKey` can take is listed
 * explicitly below, so a key added to `PaletteKey` without an entry here (or
 * vice versa) is a compile error, not a raw key string silently reaching the
 * DOM as an unresolved CSS `fill`.
 */
const SPRITE_ITEM_DEFAULT_COLOURS: Record<PaletteKey | 'ink', string> = {
    slate: PALETTE.slate,
    rust: PALETTE.rust,
    coral: PALETTE.coral,
    moss: PALETTE.moss,
    amber: PALETTE.amber,
    plum: PALETTE.plum,
    sand: PALETTE.sand,
    ink: INK,
};

/**
 * Blob as pixel art.
 *
 * One cell of a sheet per (animation, frame), drawn nearest-neighbour with no
 * interpolation between cells. The easing that makes the SVG renderer's 2fps
 * idle read as a breath is deliberately absent here: a sprite sheet swaps
 * whole frames, and tweening between them is what makes pixel art look wrong.
 * No element this renderer produces may carry a transition — and the root
 * carries `blob-anim--sprite` alongside `blob-anim` so patyourself.css can
 * exempt it from the SVG renderer's transition rule at the stylesheet level,
 * not just here.
 *
 * An animation this form has no row for holds the first idle frame rather
 * than drawing an empty cell — the same rule as an item type no renderer
 * draws yet, and for the same reason.
 */
export function SpriteBlobRenderer({
    animation,
    frame,
    features,
    items,
    abilities,
    arriving = null,
}: BlobRendererProps) {
    // Abilities hang off the hand anchor, which is out of scope for this
    // branch entirely.
    void abilities;

    const form = formFor(features);
    const row = rowFor(form, animation);
    const fallback = row === null;
    const drawnRow = fallback ? (rowFor(form, 'idle') ?? 0) : row;
    const drawnFrame = fallback ? 0 : frame;
    const columns = columnsOf(form);

    return (
        <g
            className="blob-anim blob-anim--sprite"
            data-animation={animation}
            data-frame={frame}
            data-form={form.feature}
            data-fallback={fallback ? 'idle' : undefined}
        >
            <g transform={translate([-CELL / 2, FLOOR - form.foot])}>
                <svg
                    className="blob-sprite"
                    x={0}
                    y={0}
                    width={CELL}
                    height={CELL}
                    viewBox={`${drawnFrame * CELL} ${drawnRow * CELL} ${CELL} ${CELL}`}
                >
                    <image
                        href={form.sheet}
                        width={columns * CELL}
                        height={form.animations.length * CELL}
                        style={{ imageRendering: 'pixelated' }}
                    />
                </svg>

                {items.map((item, index) => {
                    const spec = SPRITE_ITEMS[item.type];

                    // An item type the ladder names but this renderer has no
                    // geometry for is skipped rather than drawn as a gap —
                    // the SVG renderer's existing contract, kept.
                    if (spec === undefined) {
                        return null;
                    }

                    // `SPRITE_ITEM_DEFAULT_COLOURS` is total over
                    // `PaletteKey | 'ink'`, the same type `colourKey` is
                    // declared as, so this lookup can never miss — no `??`
                    // fallback to a raw key string needed.
                    const defaultColour =
                        SPRITE_ITEM_DEFAULT_COLOURS[spec.colourKey];
                    const colour =
                        item.variant === null
                            ? defaultColour
                            : (PALETTE[item.variant] ?? defaultColour);
                    const [anchorX, anchorY] = anchorFor(
                        form,
                        spec.anchor,
                        fallback ? 'idle' : animation,
                        drawnFrame,
                    );
                    const isArriving =
                        arriving !== null &&
                        arriving.type === item.type &&
                        arriving.variant === item.variant;

                    return (
                        <g
                            key={`${item.type}-${item.variant ?? 'plain'}-${index}`}
                            // The enclosing `<g>` is translated by
                            // `-CELL / 2` so the sprite's own centre column
                            // sits at x=0 (see the sheet's own transform
                            // above) — but every anchor's `x` is measured
                            // from that same centre line, in the same units
                            // the anchor table already uses. Undoing the
                            // enclosing shift here is what puts x=0 back at
                            // the cell's left edge for this nested group, so
                            // the anchor's own centre-relative x lands where
                            // the anchor table says it should, not 32px off
                            // to the left of it.
                            transform={translate([anchorX + CELL / 2, anchorY])}
                            className={
                                isArriving
                                    ? 'blob-layer blob-layer--arriving'
                                    : 'blob-layer'
                            }
                        >
                            {spec.render(colour, form)}
                        </g>
                    );
                })}
            </g>
        </g>
    );
}

/**
 * Two circles, or two short arcs when Blob's eyes are shut. Drawn from the same
 * centres either way, so a blink cannot shift where Blob is looking.
 */
function Eyes({ closed }: { closed: boolean }) {
    if (!closed) {
        return (
            <>
                <circle cx={-8} cy={17} r={4} fill={INK} />
                <circle cx={8} cy={17} r={4} fill={INK} />
            </>
        );
    }

    return (
        <g
            fill="none"
            stroke={INK}
            strokeWidth={2.5}
            strokeLinecap="round"
            data-testid="blob-eyes-closed"
        >
            <path d="M -12 17 Q -8 21 -4 17" />
            <path d="M 4 17 Q 8 21 12 17" />
        </g>
    );
}

/**
 * One worn layer, positioned by its anchor and nothing else.
 *
 * `arriving` marks the layer earned most recently, which fades in once on
 * mount. `prefers-reduced-motion` turns it off globally in patyourself.css.
 */
function Worn({ item, arriving }: { item: BlobItem; arriving: boolean }) {
    const spec = ITEMS[item.type];

    // An item type the ladder names but no renderer draws yet is skipped rather
    // than rendered as a gap.
    if (spec === undefined) {
        return null;
    }

    const colour =
        item.variant === null
            ? spec.colour
            : (PALETTE[item.variant] ?? spec.colour);

    return (
        <g
            transform={translate(ANCHOR[spec.anchor])}
            className={
                arriving ? 'blob-layer blob-layer--arriving' : 'blob-layer'
            }
        >
            {spec.render(colour)}
        </g>
    );
}

/**
 * The one place `(animation, frame)` becomes a pose.
 *
 * Adding a seventh animation means an entry in companion-animations.ts and a
 * case here. Nothing else in the app learns about it.
 *
 * `idle` is squash-and-stretch and nothing else: scaleY between 1 and 0.975,
 * anchored at the feet so Blob settles into the floor rather than shrinking
 * toward its own middle. It has to be small enough that you read it as being
 * alive rather than as something moving.
 *
 * The transition duration travels with the animation, because it is the frame
 * rate that decides whether easing helps. Two frames a second want easing or
 * they step; eight frames a second want almost none or they smear.
 */
function bodyTransform(
    animation: AnimationName,
    frame: number,
    hasLegs: boolean,
): CSSProperties {
    const floor = BODY.h + (hasLegs ? LEG_LENGTH : 0);
    const origin = {
        transformOrigin: `0px ${floor}px`,
        transformBox: 'view-box' as const,
    };

    switch (animation) {
        case 'idle':
            return {
                ...origin,
                transform: `scaleY(${frame === 1 ? 0.975 : 1})`,
                transitionDuration: '420ms',
            };

        case 'walk':
            // The bob rides on the mid-step frames, where a real step is at its
            // highest. The legs do the rest — see legTransform().
            return {
                ...origin,
                transform: `translateY(${frame % 2 === 1 ? -1.5 : 0}px)`,
                transitionDuration: '140ms',
            };

        case 'pet':
            // Leaning in, then most of the way back. It does not return all the
            // way on the last frame — the ambient picks that up, so the lean
            // resolves into the breath rather than snapping out of it.
            return {
                ...origin,
                transform: `rotate(${[0, 5, 6, 2][frame] ?? 0}deg)`,
                transitionDuration: '90ms',
            };

        case 'play':
            return {
                ...origin,
                transform: `translateY(${[0, -4, -8, -8, -4, 0][frame] ?? 0}px)`,
                transitionDuration: '90ms',
            };

        case 'notice':
            // A small lift, a settle, and back to standing — half the height
            // of play's hop, because this one was not asked for. No copy and
            // never congratulating anywhere near it: movement is the whole
            // reward.
            return {
                ...origin,
                transform: `translateY(${[0, -3, -1, 0][frame] ?? 0}px) scaleY(${[1, 1.03, 0.99, 1][frame] ?? 1})`,
                transitionDuration: '90ms',
            };

        case 'wave':
            // No arms to raise, so Blob waves with the whole of itself: a
            // tilt one way, further the other, and back to standing. The
            // asymmetry is what stops it reading as a metronome.
            return {
                ...origin,
                transform: `rotate(${[0, -7, 9, 0][frame] ?? 0}deg)`,
                transitionDuration: '110ms',
            };

        case 'jump':
            // Crouch, leave the ground, land where it started — the
            // ladder's own words. The squash on frame 1 is what sells the
            // takeoff; without it the body just slides upward.
            return {
                ...origin,
                transform: `translateY(${[0, 1, -7, 0][frame] ?? 0}px) scaleY(${[1, 0.94, 1.02, 1][frame] ?? 1})`,
                transitionDuration: '90ms',
            };

        // blink changes the eyes and nothing else. Holding the body still is
        // what makes it read as a blink rather than a flinch.
        default:
            return {
                ...origin,
                transform: 'scaleY(1)',
                transitionDuration: '0ms',
            };
    }
}

/**
 * A leg's swing. `side` is 1 for the left leg and -1 for the right, so the two
 * are mirrored from one table rather than two.
 */
function legTransform(
    animation: AnimationName,
    frame: number,
    side: number,
): CSSProperties {
    if (animation !== 'walk') {
        return {};
    }

    const swing = ([7, 0, -7, 0][frame] ?? 0) * side;

    return {
        transform: `rotate(${swing}deg)`,
        transformOrigin: `0px ${BODY.h}px`,
        transformBox: 'view-box',
        transitionDuration: '140ms',
    };
}

/** Which animations shut Blob's eyes, and on which frames. */
function eyesClosed(animation: AnimationName, frame: number): boolean {
    if (animation === 'blink') {
        return frame === 0;
    }

    // Pet keeps them shut throughout — a quarter of a second of contentment,
    // rather than a wink.
    return animation === 'pet';
}

function translate([x, y]: readonly [number, number]): string {
    return `translate(${x} ${y})`;
}
