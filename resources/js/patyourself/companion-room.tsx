/**
 * Blob's room.
 *
 * A single-screen interior: back wall, floor line, one window. Flat geometry in
 * the same style as Blob, and drawn in Blob's own coordinate system so the body
 * needs no transform to stand on the floor — the floor is simply the line
 * Blob's feet were always at.
 *
 * The room is a record of what happened. It draws what has been earned and
 * nothing else: no greyed-out object, no silhouette, no empty slot. A room with
 * two things in it reads as a room; a room with two things and six grey
 * outlines reads as a task list.
 */
import type { ReactNode } from 'react';

import { BlobRenderer, FLOOR } from '@/patyourself/blob-renderer';
import { arrivingItem, describe } from '@/patyourself/companion';
import type { CompanionData, RoomPalette } from '@/patyourself/companion';
import type { AnimationName } from '@/patyourself/companion-animations';

/**
 * Wider and shorter than Blob's own box, and sharing its origin: x is measured
 * from the centre of the room, which is where Blob stands, and `FLOOR` is the
 * floor.
 */
const ROOM = { x: -72, y: -38, w: 144, h: 114 };
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
            <g>
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
};

/**
 * Which part of the day it is, from the CLIENT clock.
 *
 * Server time would be wrong for anyone not sitting on top of the server, and
 * the whole point is that the room looks different at breakfast and at dinner
 * for the person actually looking at it.
 *
 * Entries are read in `from` order and the last one that has started wins.
 * Before the earliest start the day has not begun yet, so it is still whatever
 * the last entry is — that is how night wraps past midnight without needing a
 * fourth state to describe the small hours.
 */
export function partOfDay(
    hour: number,
    room: Record<string, RoomPalette>,
): string {
    const parts = Object.entries(room).sort((a, b) => a[1].from - b[1].from);

    if (parts.length === 0) {
        return 'day';
    }

    const started = parts.filter(([, palette]) => palette.from <= hour);

    return started.length === 0
        ? parts[parts.length - 1][0]
        : started[started.length - 1][0];
}

export function CompanionRoom({
    companion,
    animation,
    frame,
    hour = new Date().getHours(),
    className = '',
}: {
    companion: CompanionData;
    animation: AnimationName;
    frame: number;
    /** Overridable so the room can be tested at a fixed time of day. */
    hour?: number;
    className?: string;
}) {
    if (!companion.features.includes('blob')) {
        return null;
    }

    const part = partOfDay(hour, companion.room);
    const palette = companion.room[part] ?? {
        from: 0,
        wall: '#EFE6D6',
        window: '#B9D5E4',
    };

    const objects = companion.room_objects
        .map((name) => [name, ROOM_OBJECTS[name]] as const)
        .filter(([, spec]) => spec !== undefined);

    return (
        <svg
            viewBox={ROOM_VIEWBOX}
            role="img"
            aria-label={`${describe(companion)}, at home`}
            data-part-of-day={part}
            className={['blob-room', className].filter(Boolean).join(' ')}
        >
            <rect
                x={ROOM.x}
                y={ROOM.y}
                width={ROOM.w}
                height={FLOOR - ROOM.y}
                fill={palette.wall}
            />

            {/* The floor is the wall colour under a flat shadow rather than its
                own value, so a new time of day is still two colours in config
                and not four. */}
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

            <g>
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

            {objects.map(([name, spec]) => (
                <g key={name} data-room-object={name}>
                    {spec.render(palette)}
                </g>
            ))}

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
