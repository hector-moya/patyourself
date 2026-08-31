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
