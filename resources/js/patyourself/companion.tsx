/**
 * Blob.
 *
 * One SVG, layered groups, fixed anchors. Everything Blob wears or does
 * hangs off an anchor, so adding an accessory never touches the body — that is
 * the whole reason the anchors are constants and not numbers scattered through
 * the shapes.
 *
 * Blob represents the work, not the user. Nothing here has a sad state, a
 * diminished state or a state that regresses: the range is neutral to warm, and
 * once a layer is on it stays on.
 *
 * The same component renders the 32px corner instance on Today and the big one
 * on /companion. Nothing is sized in pixels — the viewBox does the scaling.
 */
import type { ReactNode } from 'react';

/** The body box, in viewBox units. x is measured from Blob's centre line. */
const BODY = { w: 44, h: 40, rx: 13 };

/**
 * Where things attach. Anything added later hangs off one of these four rather
 * than off the body's own geometry.
 *
 * `feet` is the body's underside, not the ground: legs hang from it, and
 * anything worn on the feet sits `LEG_LENGTH` further down.
 */
const ANCHOR = {
    head: [0, 0],
    neck: [0, 31],
    feet: [0, BODY.h],
    hand: [26, 34],
} as const;

const LEG_LENGTH = 12;

/**
 * Blob's own colour, as a literal rather than a theme token: Blob looks like
 * itself in light mode and in dark mode. Accessories are free to vary; the body
 * is not, because a Blob that changes colour with the page is a different Blob.
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
    /** Added to the root element; the animation itself lives in patyourself.css. */
    className: string;
    /** Anything the pose needs drawn that the body does not already have. */
    extra?: (colour: string) => ReactNode;
}

/**
 * What Blob can do. Unbounded — the interesting track. An ability with no entry
 * here is simply not drawn yet, which is why the ladder can name `wave` long
 * before anyone animates it.
 */
const ABILITIES: Record<string, AbilitySpec> = {
    walk: { className: 'blob-walk' },
};

export interface CompanionItemData {
    type: string;
    variant: string | null;
}

export interface CompanionUnlockData {
    kind: 'body' | 'item' | 'ability';
    name: string;
    variant: string | null;
    message: string;
    unlocked_at: string | null;
}

export interface CompanionData {
    log_count: number;
    insight_count: number;
    stage_index: number;
    features: string[];
    items: CompanionItemData[];
    abilities: string[];
    unlocks: CompanionUnlockData[];
    latest_unlock: CompanionUnlockData | null;
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
}: {
    companion: CompanionData;
    size?: number;
    className?: string;
}) {
    if (!companion.features.includes('blob')) {
        return null;
    }

    const hasLegs = companion.features.includes('legs');
    const poses = companion.abilities
        .map((ability) => ABILITIES[ability])
        .filter((pose): pose is AbilitySpec => pose !== undefined);

    return (
        <svg
            viewBox="-32 -22 64 84"
            width={size}
            height={size * (84 / 64)}
            role="img"
            aria-label={describe(companion)}
            className={[
                'blob',
                ...poses.map((pose) => pose.className),
                className,
            ]
                .filter(Boolean)
                .join(' ')}
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
                    />
                    <rect
                        className="blob-legs__right"
                        x={4}
                        y={BODY.h - 4}
                        width={7}
                        height={LEG_LENGTH + 4}
                        rx={3.5}
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
                <circle cx={-8} cy={17} r={4} fill={INK} />
                <circle cx={8} cy={17} r={4} fill={INK} />
            </g>

            {companion.items.map((item, index) => (
                <Worn
                    key={`${item.type}-${item.variant ?? 'plain'}-${index}`}
                    item={item}
                    arriving={isLatest(
                        companion,
                        'item',
                        item.type,
                        item.variant,
                    )}
                />
            ))}

            {poses.map((pose, index) =>
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
        </svg>
    );
}

/**
 * One worn layer, positioned by its anchor and nothing else.
 *
 * `arriving` marks the layer earned most recently, which fades in once on mount
 * — the only transition in the whole component. `prefers-reduced-motion` turns
 * it off globally in patyourself.css.
 */
function Worn({
    item,
    arriving,
}: {
    item: CompanionItemData;
    arriving: boolean;
}) {
    const spec = ITEMS[item.type];

    // An item type the ladder names but this component has not drawn yet is
    // skipped rather than rendered as a gap.
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

function translate([x, y]: readonly [number, number]): string {
    return `translate(${x} ${y})`;
}

function isLatest(
    companion: CompanionData,
    kind: string,
    name: string,
    variant: string | null,
): boolean {
    const latest = companion.latest_unlock;

    return (
        latest !== null &&
        latest.kind === kind &&
        latest.name === name &&
        latest.variant === variant
    );
}

/**
 * What a screen reader is told. States what Blob has, in the same register as
 * the copy: a description, never a score.
 */
function describe(companion: CompanionData): string {
    const worn = companion.items.map((item) =>
        item.variant === null ? item.type : `${item.variant} ${item.type}`,
    );
    const parts = [...worn, ...companion.abilities];

    return parts.length === 0 ? 'Blob' : `Blob, with ${parts.join(', ')}`;
}
