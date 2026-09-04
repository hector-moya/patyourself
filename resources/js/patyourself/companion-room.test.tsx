import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { CompanionRoom, partOfDay } from './companion-room';
import { companion } from './companion.fixture';

const ROOM = companion().room;

function room(overrides = {}, hour = 12) {
    return render(
        <CompanionRoom
            companion={companion(overrides)}
            animation="idle"
            frame={0}
            hour={hour}
        />,
    ).container;
}

describe('partOfDay', () => {
    it('reads the hour against the parts config defines', () => {
        expect(partOfDay(9, ROOM)).toBe('day');
        expect(partOfDay(17, ROOM)).toBe('day');
        expect(partOfDay(19, ROOM)).toBe('dusk');
        expect(partOfDay(22, ROOM)).toBe('night');
    });

    /**
     * The small hours are still night. Wrapping past midnight falls out of
     * reading the parts in order rather than out of a fourth state describing
     * 3am.
     */
    it('wraps past midnight without a fourth state', () => {
        expect(partOfDay(0, ROOM)).toBe('night');
        expect(partOfDay(3, ROOM)).toBe('night');
        expect(partOfDay(6, ROOM)).toBe('night');
    });
});

describe('CompanionRoom', () => {
    it('renders nothing before Blob exists', () => {
        const { container } = render(
            <CompanionRoom
                companion={companion({ features: [], unlocks: [] })}
                animation="idle"
                frame={0}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('stands Blob on the floor of a room with a window', () => {
        const container = room({ features: ['blob', 'legs'] });

        expect(container.querySelector('.blob-room')).not.toBeNull();
        expect(container.querySelector('.blob-body')).not.toBeNull();
    });

    /**
     * The cheapest liveness in the feature: the room looks different at
     * breakfast and at dinner without the user doing anything.
     */
    it('tints the wall and window by the time of day', () => {
        const day = room({}, 12);
        const night = room({}, 23);

        expect(
            day.querySelector('.blob-room')?.getAttribute('data-part-of-day'),
        ).toBe('day');
        expect(
            night.querySelector('.blob-room')?.getAttribute('data-part-of-day'),
        ).toBe('night');

        expect(day.innerHTML).toContain('#EFE6D6');
        expect(night.innerHTML).toContain('#2F3A40');
        expect(night.innerHTML).not.toContain('#EFE6D6');
    });

    it('puts an earned object in the room', () => {
        const container = room({ room_objects: ['bookshelf'] });

        expect(
            container.querySelector('[data-room-object="bookshelf"]'),
        ).not.toBeNull();
    });

    /**
     * The rule the whole room turns on. An unearned object is not drawn greyed
     * out, not silhouetted and not as an empty slot — a room with two things
     * and six grey outlines is a task list.
     */
    it('never draws an object that has not been earned', () => {
        const container = room({ room_objects: [] });

        expect(container.querySelector('[data-room-object]')).toBeNull();
        expect(container.querySelectorAll('[data-room-object]')).toHaveLength(
            0,
        );
    });

    it('skips a room object no dictionary entry draws yet', () => {
        const container = room({ room_objects: ['aquarium'] });

        expect(container.querySelector('[data-room-object]')).toBeNull();
        expect(container.querySelector('.blob-body')).not.toBeNull();
    });
});

describe('scenes', () => {
    it('draws the forest backdrop for a record that is still outside', () => {
        // room_objects is set here (rather than left at the fixture's
        // default empty list) so this is a real test of the guard: without
        // it, the bookshelf assertion below would pass whether or not the
        // forest actually excludes ROOM_OBJECTS.
        const container = room({
            scene: 'forest',
            room_objects: ['bookshelf'],
        });

        expect(container.querySelector('.scene-backdrop')).not.toBeNull();
        expect(container.querySelector('.room-object--bookshelf')).toBeNull();
    });

    it('draws the room, with everything in it, for a record that is indoors', () => {
        const container = room({
            scene: 'cabin',
            room_objects: ['bookshelf'],
        });

        expect(
            container.querySelector('.room-object--bookshelf'),
        ).not.toBeNull();
    });

    /**
     * Every other test in this file renders the forest (if at all) at noon,
     * so a renamed part, a dropped one, or two parts silently pointed at the
     * same PNG would all ship unnoticed — the reader would just see flat
     * green at night. This walks the whole day and checks both that a
     * backdrop actually loads at each part and that no two parts show the
     * same art.
     *
     * A full four-part day is passed explicitly, in the same shape as
     * `config('companion.room')`, rather than relying on the shared
     * fixture's default `room` — that default only has three parts (see the
     * `partOfDay` tests above, which depend on hour 6 wrapping to `night`
     * without a `sunrise` entry) and changing it here would break those.
     */
    it('draws a distinct backdrop for every part of the day, not just noon', () => {
        const fullDay = {
            sunrise: {
                from: 5,
                wall: '#F2E0D0',
                window: '#F0B98A',
                light: '#F4A15C',
                dim: 0.18,
            },
            day: {
                from: 8,
                wall: '#EFE6D6',
                window: '#B9D5E4',
                light: '#FFFFFF',
                dim: 0,
            },
            dusk: {
                from: 18,
                wall: '#E7D2BE',
                window: '#E9A468',
                light: '#E2762F',
                dim: 0.22,
            },
            night: {
                from: 21,
                wall: '#2F3A40',
                window: '#1A2530',
                light: '#2B3F6B',
                dim: 0.42,
            },
        };

        const hrefs = [6, 12, 19, 23].map((hour) => {
            const backdrop = room(
                { scene: 'forest', room: fullDay },
                hour,
            ).querySelector('.scene-backdrop');

            expect(backdrop).not.toBeNull();

            return backdrop?.getAttribute('href');
        });

        expect(new Set(hrefs).size).toBe(4);
    });
});

describe('room objects', () => {
    it('draws an object the record has earned', () => {
        expect(
            room({ room_objects: ['rug'] }).querySelector('.room-object--rug'),
        ).not.toBeNull();
    });

    it('skips an object it does not know rather than leaving a gap', () => {
        const container = room({ room_objects: ['spaceship'] });

        expect(container.querySelector('.room-object--spaceship')).toBeNull();
        // The room itself still renders.
        expect(container.querySelector('svg')).not.toBeNull();
    });

    it('draws every object the record has earned, at once', () => {
        const container = room({
            room_objects: ['bookshelf', 'rug', 'lamp', 'plant', 'stool'],
        });

        expect(
            container.querySelectorAll('[class*="room-object--"]'),
        ).toHaveLength(5);
    });
});
