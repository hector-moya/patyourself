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

/** Accessory colours, and the variants a recolour can name. */
const PALETTE: Record<string, string> = {
    slate: '#3E4A55',
    rust: '#C25B4A',
    coral: '#E8836B',
    moss: '#6E8F5A',
    amber: '#D4942E',
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
 */
const ABILITIES: Record<string, AbilitySpec> = {};

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
 * Blob as pixel art. Not implemented — this exists so the seam is real rather
 * than hypothetical, and so the day sprites arrive nothing outside this file
 * has to change.
 *
 * TODO: draw from a sprite sheet, one cell per (animation, frame), nearest
 * neighbour, no interpolation between cells. The easing that makes the SVG
 * renderer's 2fps idle read as a breath must NOT be carried over — a sprite
 * sheet swaps whole frames, and tweening between them is what makes pixel art
 * look wrong.
 */
export function SpriteBlobRenderer(props: BlobRendererProps) {
    // The signature is the contract and is deliberately the real one; only the
    // drawing is missing.
    void props;

    return null;
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
