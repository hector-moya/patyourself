/**
 * The mark beside a line in Blob's record: one 8×8 glyph per kind of unlock.
 *
 * Drawn as literal cells rather than set in an icon font, for the same reason
 * the panels around them are nine-sliced art — this is the one surface in the
 * app that is a world rather than a notebook, and a stroked lucide icon next
 * to a sprite sheet reads as a different application bleeding through.
 *
 * Three kinds, and the difference is what happened: a sprout for a part of the
 * body, a crate for something worn, a spark for something learned. Colour
 * carries the same distinction, so the rows still separate at a glance for
 * anyone who cannot resolve an 8×8 silhouette.
 *
 * The grids are written as the picture they are, `#` lit and `.` empty, rather
 * than as a list of coordinates. A glyph is art, and art that can only be read
 * by plotting twenty pairs on paper is art nobody will ever correct.
 */
import type { CompanionUnlockData } from '@/patyourself/companion';

interface GlyphSpec {
    color: string;
    rows: readonly string[];
}

const GLYPHS: Record<string, GlyphSpec> = {
    /** A sprout: two leaves over a stem, sitting in a spread of ground. */
    body: {
        color: '#8FBF6A',
        rows: [
            '...##...',
            '..#..#..',
            '...##...',
            '...##...',
            '..####..',
            '.##..##.',
            '..####..',
            '........',
        ],
    },

    /** A crate: something that arrived, and is now carried. */
    item: {
        color: '#E9B96B',
        rows: [
            '........',
            '........',
            '.######.',
            '.#....#.',
            '.#.##.#.',
            '.#....#.',
            '.######.',
            '........',
        ],
    },

    /** A spark: something Blob can now do that it could not before. */
    ability: {
        color: '#7BA6BB',
        rows: [
            '...##...',
            '...##...',
            '.#....#.',
            '..####..',
            '...##...',
            '..#..#..',
            '...##...',
            '...##...',
        ],
    },
};

/**
 * A kind this file does not know falls back to `body` rather than drawing
 * nothing — the same contract item types, room objects, scenes and animations
 * already follow. The record is history, and a line of it must never lose its
 * mark because the ladder learned a fourth word.
 *
 * `Object.hasOwn` rather than `GLYPHS[kind] ?? …` for the reason `sceneFor`
 * spells out: a plain object's lookup walks the prototype chain, so a kind
 * like `'constructor'` resolves to something truthy that the `??` never
 * catches, and the fall then happens downstream on `.rows`.
 */
function glyphFor(kind: string): GlyphSpec {
    return Object.hasOwn(GLYPHS, kind) ? GLYPHS[kind] : GLYPHS.body;
}

export function CompanionGlyph({
    kind,
    size = 18,
}: {
    kind: CompanionUnlockData['kind'] | string;
    size?: number;
}) {
    const glyph = glyphFor(kind);

    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 8 8"
            aria-hidden="true"
            className="companion-glyph"
            data-glyph={Object.hasOwn(GLYPHS, kind) ? kind : 'body'}
        >
            {glyph.rows.flatMap((row, y) =>
                [...row].map((cell, x) =>
                    cell === '#' ? (
                        <rect
                            key={`${x}-${y}`}
                            x={x}
                            y={y}
                            width={1}
                            height={1}
                            fill={glyph.color}
                        />
                    ) : null,
                ),
            )}
        </svg>
    );
}
