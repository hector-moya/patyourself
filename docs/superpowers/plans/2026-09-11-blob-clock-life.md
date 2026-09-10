# Blob — a life on the clock: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Blob sleeps through the night, stretches in the morning and looks around during the day — driven by the clock and by config, and by nothing in the record.

**Architecture:** `partOfDay` moves into a new `part-of-day.ts` that also owns `RoomPalette` and two new predicates (`asleepAt`, `wakingAt`), reading an authored `asleep` flag on one part of `config('companion.room')`. `ambientFor` becomes a two-rung precedence list — sleep, then walk-or-idle — and `selfStartedFor` becomes part-aware, returning nothing at all while Blob is asleep. Three animations join the registry (`sleep`, `stretch`, `look`), each with a row on the `arms` sheet and `sleep` also on `blob` and `legs`.

**Tech Stack:** Laravel 13, Inertia v3, React 19, TypeScript, vitest, PHPUnit, Pixel Lab MCP for the art, PIL (Python 3, already installed) for composing and measuring sheets.

**Spec:** `docs/superpowers/specs/2026-09-11-blob-clock-life-design.md` — read it before Task 1. It carries the reasoning behind every decision below, and the argument you will need if you are tempted to change one.

## Global Constraints

- **Nothing that decides what Blob is doing may read the record.** `ambientFor` and `selfStartedFor` may read `companion.room`, `companion.abilities` and the hour. Not `log_count`, not `insight_count`, not `unlocks`, not `latest_unlock`, not any date. This is the rule the whole feature turns on.
- **Banned vocabulary, scanned including comments:** `streak`, `congratulation`, `well done`, `completion rate`, `percent`, `points`, `level up`, `lonely`, `hungry`, `misses you`, `neglect`, `cooldown`. `points` and `percent` are substring traps — *endpoints* and *percentage* both trip them. `lonely`, `hungry` and `neglect` are the ones a sleep feature reaches for. Do not.
- **Any new companion source file goes on `CompanionVocabularyTest::sourceFiles()`.** A file absent from that list is scanned by nothing.
- **Copy rules:** sentence case, no exclamation marks, never congratulating, no second person keeping score. Say what Blob did, never how the person is doing.
- **No second `requestAnimationFrame` loop, ever.** `use-sprite-clock.ts` runs exactly one at module scope and `use-sprite-clock.test.ts` guards it. This plan needs no timer of any kind beyond the auto-timer that already exists.
- **Every per-frame anchor delta has `x = 0`.** Anchor `x` is measured from the cell's *centre*, signed; `y` is rows from the top. Reading `x` as an offset from the left edge once put an accessory 32px off the body.
- **In the sprite art, the body never rotates and never translates sideways.** In the SVG renderer, rotation is fine and precedented (`pet` and `wave` both rotate) because accessories live inside the transformed group. Do not carry the SVG freedom into a Pixel Lab brief.
- **Art is committed. No runtime fetch, ever.**
- **Do not create new base directories** and **do not change dependencies** without approval.
- **Run `vendor/bin/pint --dirty --format agent`** after touching any PHP.
- **Tests are SQLite, production is MySQL.** A green suite is not evidence raw SQL works. No raw SQL in this plan.
- **Every task ends with all five verification commands:**

```
npm run build && php artisan test --compact && npx vitest run && npx tsc --noEmit && vendor/bin/pint --dirty --format agent && npm run lint
```

- **Baseline, measured on this branch before Task 1: 1000 PHP tests / 6319 assertions, 442 JS tests, 0 TypeScript errors.** `tsc` is a floor — any error is a regression. Test counts only ever go up.

## Task dependencies

Tasks 1 and 2 are independent of each other and may run in parallel. Task 3 needs Task 2's art. Task 4 needs Task 1's predicates and Task 3's registry. Task 5 needs everything.

## File Structure

| File | Responsibility | Task |
| --- | --- | --- |
| `resources/js/patyourself/part-of-day.ts` | **new.** `RoomPalette`, `partOfDay`, `asleepAt`, `wakingAt`. The only file that knows how to read a part of the day. | 1 |
| `resources/js/patyourself/part-of-day.test.ts` | **new.** The three moved `partOfDay` cases plus the two new predicates and every error-handling row. | 1 |
| `config/companion.php` | `'asleep' => true` on the night part. | 1 |
| `app/Services/Companion/CompanionState.php` | Array-shape docblock gains `asleep?: bool`. No behaviour change — `toArray()` already passes `room` through whole. | 1 |
| `resources/js/patyourself/companion.tsx` | Imports `RoomPalette` rather than declaring it. `ambientFor` and `selfStartedFor` gain an `hour`. | 1, 4 |
| `resources/js/patyourself/companion-room.tsx` | Imports both `RoomPalette` and `partOfDay`; stops exporting `partOfDay`. | 1 |
| `resources/js/patyourself/companion.fixture.ts` | Night gains `asleep: true`, mirroring config. | 1 |
| `resources/js/patyourself/sprites/humus-{blob,legs,arms}.png` | The art. Five new rows across three sheets. | 2 |
| `resources/js/patyourself/sprites/README.md` | Rows, frame choices and every measurement. | 2 |
| `resources/js/patyourself/companion-animations.ts` | Three registry entries. Data only. | 3 |
| `resources/js/patyourself/sprite-layout.ts` | New rows in `FORMS`, plus each row's per-frame offsets. | 3 |
| `resources/js/patyourself/blob-renderer.tsx` | Three `bodyTransform` cases; `eyesClosed` learns about sleep. | 3 |
| `docs/BLOB.md` | The current-state reference. Updated last, once the pictures are real. | 5 |

---

### Task 1: The parts of the day become a module, and one of them is the sleeping one

No animation exists yet and nothing about Blob changes. This task delivers the predicates and the config flag, with the suite green throughout.

**Files:**
- Create: `resources/js/patyourself/part-of-day.ts`
- Create: `resources/js/patyourself/part-of-day.test.ts`
- Modify: `resources/js/patyourself/companion.tsx:36-45` (the `RoomPalette` declaration moves out)
- Modify: `resources/js/patyourself/companion-room.tsx:22` (import), `:195-210` (the `partOfDay` function moves out)
- Modify: `resources/js/patyourself/companion-room.test.tsx:7` (import), `:105-134` (the `partOfDay` describe block moves out)
- Modify: `resources/js/patyourself/companion.fixture.ts` (night gains the flag)
- Modify: `config/companion.php:86-91`
- Modify: `app/Services/Companion/CompanionState.php:21`
- Modify: `tests/Unit/Companion/CompanionRoomConfigTest.php`
- Modify: `tests/Feature/Companion/CompanionScreenTest.php:56-64`
- Modify: `tests/Feature/Companion/CompanionVocabularyTest.php` (`sourceFiles()`)

**Interfaces:**
- Consumes: nothing.
- Produces:
  ```ts
  export interface RoomPalette {
      from: number;
      wall: string;
      window: string;
      light: string;
      dim: number;
      asleep?: boolean;
  }
  export function partOfDay(hour: number, room: Record<string, RoomPalette>): string;
  export function asleepAt(hour: number, room: Record<string, RoomPalette>): boolean;
  export function wakingAt(hour: number, room: Record<string, RoomPalette>): boolean;
  ```

**A trap, before you start.** The shared fixture's room has **three** parts (`day` from 7, `dusk` from 18, `night` from 21) and no `sunrise`. An existing test asserts `partOfDay(6, ROOM) === 'night'`. **Adding a sunrise part to the shared fixture turns that assertion red.** Every test below that needs a four-part day builds its own room object locally.

- [ ] **Step 1: Write the failing test for the new module**

Create `resources/js/patyourself/part-of-day.test.ts`. The first three cases are moved verbatim from `companion-room.test.tsx` (same assertions, new home — the count does not drop); everything after `asleepAt` is new.

```ts
import { describe, expect, it } from 'vitest';

import { companion } from './companion.fixture';
import { asleepAt, partOfDay, wakingAt } from './part-of-day';
import type { RoomPalette } from './part-of-day';

/** The room the fixture ships: three parts, and hour 6 is still night in it. */
const ROOM = companion().room;

/**
 * A four-part day in the same shape as `config('companion.room')`, built here
 * rather than added to the shared fixture: an existing case asserts hour 6 is
 * night in the fixture's three-part room, and a sunrise part would turn it red.
 */
function fourParts(
    overrides: Record<string, Partial<RoomPalette>> = {},
): Record<string, RoomPalette> {
    const base: Record<string, RoomPalette> = {
        sunrise: { from: 5, wall: '#F2E0D0', window: '#F0B98A', light: '#F4A15C', dim: 0.18 },
        day: { from: 8, wall: '#EFE6D6', window: '#B9D5E4', light: '#FFFFFF', dim: 0 },
        dusk: { from: 18, wall: '#E7D2BE', window: '#E9A468', light: '#E2762F', dim: 0.22 },
        night: { from: 21, wall: '#2F3A40', window: '#1A2530', light: '#2B3F6B', dim: 0.42, asleep: true },
    };

    for (const [name, patch] of Object.entries(overrides)) {
        base[name] = { ...base[name], ...patch };
    }

    return base;
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

    it('falls back to day when the config names no parts at all', () => {
        expect(partOfDay(9, {})).toBe('day');
        expect(partOfDay(23, {})).toBe('day');
    });
});

describe('asleepAt', () => {
    it('is true through the part config flags, and false everywhere else', () => {
        expect(asleepAt(22, fourParts())).toBe(true);
        expect(asleepAt(3, fourParts())).toBe(true);
        expect(asleepAt(6, fourParts())).toBe(false);
        expect(asleepAt(12, fourParts())).toBe(false);
        expect(asleepAt(19, fourParts())).toBe(false);
    });

    /**
     * The flag is the whole mechanism, so a room without it has to behave
     * exactly as this feature's rooms did before it existed. This is also what
     * makes the flag falsifiable: the fixture's own night carries it, so
     * without this case a test asserting sleep would pass against a default.
     */
    it('is false for a room whose parts carry no flag at all', () => {
        expect(asleepAt(22, fourParts({ night: { asleep: undefined } }))).toBe(false);
        expect(asleepAt(3, ROOM_WITHOUT_FLAG)).toBe(false);
    });

    it('reads the flag as a value, not as truthiness', () => {
        // A part that says anything other than `true` is awake.
        const room = fourParts({ night: { asleep: false } });

        expect(asleepAt(22, room)).toBe(false);
    });

    it('is false when the config names no parts at all', () => {
        expect(asleepAt(22, {})).toBe(false);
    });

    it('sleeps through every part when config flags every part', () => {
        const room = fourParts({
            sunrise: { asleep: true },
            day: { asleep: true },
            dusk: { asleep: true },
        });

        expect(asleepAt(6, room)).toBe(true);
        expect(asleepAt(12, room)).toBe(true);
    });
});

describe('wakingAt', () => {
    /**
     * Derived, not named: this room's parts are called nonsense and the hours
     * are unchanged, so the only thing left to key off is the order. A
     * hardcoded 'sunrise' fails here.
     */
    it('is the part that follows the sleeping one, whatever it is called', () => {
        const renamed: Record<string, RoomPalette> = {
            morgunn: { from: 5, wall: '#F2E0D0', window: '#F0B98A', light: '#F4A15C', dim: 0.18 },
            dagur: { from: 8, wall: '#EFE6D6', window: '#B9D5E4', light: '#FFFFFF', dim: 0 },
            kvold: { from: 21, wall: '#2F3A40', window: '#1A2530', light: '#2B3F6B', dim: 0.42, asleep: true },
        };

        expect(wakingAt(6, renamed)).toBe(true);
        expect(wakingAt(12, renamed)).toBe(false);
        expect(wakingAt(22, renamed)).toBe(false);
    });

    it('is true only in the waking part of a four-part day', () => {
        expect(wakingAt(6, fourParts())).toBe(true);
        expect(wakingAt(9, fourParts())).toBe(false);
        expect(wakingAt(19, fourParts())).toBe(false);
        expect(wakingAt(23, fourParts())).toBe(false);
    });

    it('is never true while Blob is asleep', () => {
        const room = fourParts({
            sunrise: { asleep: true },
            day: { asleep: true },
            dusk: { asleep: true },
        });

        for (const hour of [0, 6, 12, 19, 22]) {
            expect(wakingAt(hour, room)).toBe(false);
        }
    });

    it('is false when no part sleeps, and when there are no parts', () => {
        expect(wakingAt(6, fourParts({ night: { asleep: undefined } }))).toBe(false);
        expect(wakingAt(6, {})).toBe(false);
    });
});
```

Add, above `describe('asleepAt')`, the room the second case needs:

```ts
/** The fixture's own three parts, with nothing flagged. */
const ROOM_WITHOUT_FLAG: Record<string, RoomPalette> = {
    day: { from: 7, wall: '#EFE6D6', window: '#B9D5E4', light: '#FFFFFF', dim: 0 },
    dusk: { from: 18, wall: '#E7D2BE', window: '#E9A468', light: '#E2762F', dim: 0.22 },
    night: { from: 21, wall: '#2F3A40', window: '#1A2530', light: '#2B3F6B', dim: 0.42 },
};
```

- [ ] **Step 2: Run it and watch it fail for the right reason**

Run: `npx vitest run resources/js/patyourself/part-of-day.test.ts`

Expected: fails to resolve `./part-of-day` — the module does not exist yet. Not a failed assertion; a missing file.

- [ ] **Step 3: Create the module**

Create `resources/js/patyourself/part-of-day.ts`. `partOfDay` moves across **with its docblock unchanged**; `RoomPalette` moves from `companion.tsx` **with its comments unchanged**, and gains one field.

```ts
/**
 * The parts of Blob's day, and what each one carries.
 *
 * `config('companion.room')` authors them and the payload relays them
 * verbatim, so this is the only file that knows how to read one. It holds the
 * palette type as well as the predicates, which is what keeps the dependency
 * pointing one way: `companion.tsx` and `companion-room.tsx` both read the
 * clock, and neither of them can be read by it. Declaring the type in
 * `companion.tsx` and the predicates here would close a cycle instead.
 *
 * Every predicate takes the hour as an argument and reads nothing else. That
 * is not a testing convenience: what Blob is doing must be a function of the
 * clock and of config, never of the record, and the cheapest way to keep that
 * true is to leave these functions nothing else to read.
 */
export interface RoomPalette {
    /** The local hour this part of the day starts at. */
    from: number;
    wall: string;
    window: string;
    /** The colour the whole scene is washed with, Blob included. */
    light: string;
    /** How strongly. Zero at midday, when the light needs no help. */
    dim: number;
    /**
     * Whether Blob sleeps through this part.
     *
     * Authored in config beside the hours rather than inferred here from the
     * part's name or from how dark its wall reads. Which part of the day a
     * creature sleeps through is a decision, and a decision belongs written
     * down; the name is data everywhere else in this feature, and `partOfDay`
     * below does not know the names either.
     *
     * Absent is awake, so a room authored before this field existed behaves
     * exactly as it did.
     */
    asleep?: boolean;
}

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
    const parts = ordered(room);

    if (parts.length === 0) {
        return 'day';
    }

    const started = parts.filter(([, palette]) => palette.from <= hour);

    return started.length === 0
        ? parts[parts.length - 1][0]
        : started[started.length - 1][0];
}

/** Whether Blob is asleep at this hour. */
export function asleepAt(
    hour: number,
    room: Record<string, RoomPalette>,
): boolean {
    return room[partOfDay(hour, room)]?.asleep === true;
}

/**
 * Whether this is the part Blob wakes up in — the one that follows a sleeping
 * part, found by order rather than by name.
 *
 * There is no waking *moment*: nothing ticks the hour, and the clock stops
 * while the tab is hidden, so a boundary crossing is not an event this app can
 * see without a timer it has no reason to own. The morning is a part of the
 * day like any other, and what makes it the morning is what came before it.
 */
export function wakingAt(
    hour: number,
    room: Record<string, RoomPalette>,
): boolean {
    const parts = ordered(room);
    const current = partOfDay(hour, room);
    const index = parts.findIndex(([name]) => name === current);

    if (index === -1 || parts[index][1].asleep === true) {
        return false;
    }

    // Wrapping backwards off the front of the list: the morning's predecessor
    // is the night, which sorts last precisely because it starts latest.
    const previous = parts[(index - 1 + parts.length) % parts.length];

    return previous[1].asleep === true;
}

/** The parts, earliest first. Shared so nothing sorts them twice differently. */
function ordered(
    room: Record<string, RoomPalette>,
): [string, RoomPalette][] {
    return Object.entries(room).sort((a, b) => a[1].from - b[1].from);
}
```

- [ ] **Step 4: Run the new test and watch it pass**

Run: `npx vitest run resources/js/patyourself/part-of-day.test.ts`

Expected: PASS, every case.

- [ ] **Step 5: Point the two existing consumers at the new module**

In `resources/js/patyourself/companion.tsx`, delete the `RoomPalette` interface (lines 36-45) and import the type instead. Keep it re-exported, because `CompanionData` names it in a public shape and `companion-room.tsx` is not the only future reader:

```ts
import type { RoomPalette } from '@/patyourself/part-of-day';

export type { RoomPalette };
```

In `resources/js/patyourself/companion-room.tsx`, delete the `partOfDay` function (lines 195-210 including its docblock, which moved with it) and change the imports:

```ts
import { arrivingItem, describe } from '@/patyourself/companion';
import type { CompanionData } from '@/patyourself/companion';
import { partOfDay } from '@/patyourself/part-of-day';
import type { RoomPalette } from '@/patyourself/part-of-day';
```

In `resources/js/patyourself/companion-room.test.tsx`, drop `partOfDay` from the import on line 7 and delete the `describe('partOfDay')` block (lines 105-134) — those three cases now live in `part-of-day.test.ts` with the same assertions.

- [ ] **Step 6: Run the whole JS suite**

Run: `npx vitest run`

Expected: PASS, and the test count is **higher than 442** — the moved cases plus the new ones. If it is lower, a moved case was dropped rather than moved.

- [ ] **Step 7: Write the failing PHP tests**

In `tests/Unit/Companion/CompanionRoomConfigTest.php`, add:

```php
    /**
     * Exactly one part of the day is the one Blob sleeps through, and it is the
     * one that starts latest. The flag is what the drawing keys off, so a
     * config that flagged `day` would put Blob to sleep at lunchtime and a
     * config that flagged none would leave it awake all night.
     */
    public function test_the_last_part_of_the_day_is_the_one_blob_sleeps_through(): void
    {
        $room = (array) config('companion.room');

        $asleep = array_keys(array_filter(
            $room,
            static fn (array $part): bool => ($part['asleep'] ?? false) === true,
        ));

        $this->assertCount(1, $asleep);

        // uasort keeps the keys, so the last one is the part that starts
        // latest — which is the part that has to be the sleeping one.
        uasort($room, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);

        $this->assertSame(array_key_last($room), $asleep[0]);
    }
```

In `tests/Feature/Companion/CompanionScreenTest.php`, add to the existing `room` assertions around line 62:

```php
                // The flag the drawing keys off, asserted at the payload's
                // edge. `toArray()` passes the room through whole today, so
                // this looks redundant — it is not: a payload silently
                // dropping a newly added key has bitten this project three
                // times in one branch.
                ->where('companion.room.night.asleep', true)
```

- [ ] **Step 8: Run them and watch them fail**

Run: `php artisan test --compact --filter=CompanionRoomConfigTest` then `php artisan test --compact --filter=CompanionScreenTest`

Expected: both fail — no part carries `asleep` yet.

- [ ] **Step 9: Author the flag and the docblock**

In `config/companion.php`, the night part gains one key. Add the comment too — the block above it explains the room, and this is the one field that is not about colour:

```php
        // `asleep` marks the part Blob sleeps through. It sits here, beside the
        // hours, rather than being decided in the drawing: which part of the day
        // a creature sleeps through is an authored decision. Nothing in the app
        // exposes it, so it is not a setting.
        'night' => ['from' => 21, 'wall' => '#2F3A40', 'window' => '#1A2530', 'light' => '#2B3F6B', 'dim' => 0.42, 'asleep' => true],
```

In `app/Services/Companion/CompanionState.php`, line 21, extend the array shape:

```php
     * @param  array<string, array{from: int, wall: string, window: string, light: string, dim: float|int, asleep?: bool}>  $room
```

In `resources/js/patyourself/companion.fixture.ts`, the night entry gains `asleep: true` so the fixture mirrors config. Leave the three-part shape alone.

In `tests/Feature/Companion/CompanionVocabularyTest.php`, add to `sourceFiles()`, next to the other `patyourself` entries:

```php
            $root.'/resources/js/patyourself/part-of-day.ts',
```

- [ ] **Step 10: Run everything**

Run each, in order:

```
npx vitest run
php artisan test --compact
npx tsc --noEmit
vendor/bin/pint --dirty --format agent
npm run lint
npm run build
```

Expected: PHP at 1002 or more tests, JS above 442, tsc silent, Pint clean, lint clean, build succeeds.

- [ ] **Step 11: Commit**

```bash
git add -A
git commit -m "feat(blob): give the parts of the day a module, and one of them a bedtime

partOfDay moves out of companion-room.tsx into a module that also owns
RoomPalette, because companion.tsx needs to read the clock and
companion-room.tsx already reads companion.tsx — declaring the type in
one and the predicates in the other closes a cycle.

Which part Blob sleeps through is an authored flag on the part itself,
not the string 'night' in TypeScript and not an inference from how dark
the wall reads. Absent is awake, so a room without it behaves as before.

Nothing draws differently yet."
```

---

### Task 2: The art — five rows across three sheets

Generate, compose, measure, record. **No TypeScript in this task**, so the suite stays green: nothing references the new rows until Task 3.

**Files:**
- Modify: `resources/js/patyourself/sprites/humus-blob.png` (128×128 → 256×192)
- Modify: `resources/js/patyourself/sprites/humus-legs.png` (128×128 → 256×192)
- Modify: `resources/js/patyourself/sprites/humus-arms.png` (384×512 → 384×704)
- Modify: `resources/js/patyourself/sprites/README.md`

**Interfaces:**
- Consumes: nothing.
- Produces: three sheets whose rows are, in order —
  - `humus-blob.png`: `idle`, `blink`, `sleep`
  - `humus-legs.png`: `idle`, `blink`, `sleep`
  - `humus-arms.png`: `idle`, `blink`, `walk`, `wave`, `jump`, `pet`, `play`, `notice`, `sleep`, `stretch`, `look`

  and a README table of per-frame `head`/`face`/`feet` deltas for each new row, from which Task 3 builds the offset tables.

**Read `resources/js/patyourself/sprites/README.md` first**, especially "Adding a form" and "Which frames were kept, and why". It records the character and state ids, the generation parameters, and the measurement method. Follow it rather than inventing one.

- [ ] **Step 1: Generate the five animations**

Pixel Lab, `animate_character`, with `mode: "v3"`, `directions: ["south"]`, `keep_first_frame: false`, on the three states recorded in README:

| Row | Character | State | Frames |
| --- | --- | --- | --- |
| `sleep` on `blob` | `60d6031a-2699-4da0-9260-f0b5b1bfdd10` | No arms no legs | 4 |
| `sleep` on `legs` | `7cb62b7a-b49f-40b7-a40a-054bf8612687` | No arms | 4 |
| `sleep` on `arms` | `5c2e3e54-39e1-45f1-9b7c-bf4c89f0872a` | Green sprout | 4 |
| `stretch` on `arms` | `5c2e3e54-39e1-45f1-9b7c-bf4c89f0872a` | Green sprout | 6 |
| `look` on `arms` | `5c2e3e54-39e1-45f1-9b7c-bf4c89f0872a` | Green sprout | 4 |

Briefs — and every one of them carries the same three constraints, because the anchor table has no concept of a tilted head or a body that has moved sideways:

- **`sleep`** — "sitting upright, fast asleep, both eyes closed the whole time, breathing slowly and deeply, head tipped very slightly forward. The body stays upright, level and centred in the frame at all times. It does not lie down, does not curl up, does not lean to either side and does not rotate."
- **`stretch`** — "waking up: a slow stretch upward, reaching, then settling back to standing. Eyes open. Both feet stay on the ground. The body stays upright, level and centred in the frame, and does not rotate or move sideways."
- **`look`** — "turning to look at something off to one side, then facing forward again. Eyes open, feet still. The body stays upright, level and centred in the frame, and does not rotate or move sideways."

**Reject and re-roll any frame where the body rotates or drifts sideways.** That is not something to fix in the anchor table. If three re-rolls do not produce an upright sleeper, fall back to the spec's stated plan: select frames from a generated row the way `idle` and `blink` already were, and if that fails, compose a two-frame slow breath from existing closed-eye frames. Record which path was taken in README.

- [ ] **Step 2: Check the frames by eye before composing anything**

Save the returned frames to `$CLAUDE_JOB_DIR/tmp/` and look at them. What disqualifies a row: a rotated body, a sideways shift, an eye that opens during `sleep`, a re-framed or re-cropped cell. Cells must keep the exact position Pixel Lab returned them at — the shared registration is the whole reason accessory alignment is a small table instead of per-frame artwork.

- [ ] **Step 3: Compose the sheets**

Rows are **appended**, never inserted — a form's row order is its `animations` array, and inserting shifts every row below it. `blob` and `legs` also widen from 2 columns to 4, because `sleep` has 4 frames and a sheet is as wide as its widest animation; existing pixels keep their exact position and the new space is transparent.

```python
from PIL import Image

CELL = 64
SPRITES = "resources/js/patyourself/sprites/"


def grow(name, columns, rows):
    """Repad a sheet onto a bigger transparent canvas, top-left aligned."""
    old = Image.open(SPRITES + name).convert("RGBA")
    new = Image.new("RGBA", (columns * CELL, rows * CELL), (0, 0, 0, 0))
    new.paste(old, (0, 0))
    return new


def place(sheet, row, frames):
    """Paste one animation's frames along a row, left to right."""
    for index, path in enumerate(frames):
        cell = Image.open(path).convert("RGBA")
        assert cell.size == (CELL, CELL), (path, cell.size)
        sheet.paste(cell, (index * CELL, row * CELL))
    return sheet


# blob and legs: idle, blink, sleep — 4 columns because sleep is 4 frames.
for form in ("blob", "legs"):
    sheet = grow(f"humus-{form}.png", 4, 3)
    place(sheet, 2, SLEEP_FRAMES[form])          # your saved frame paths
    sheet.save(SPRITES + f"humus-{form}.png")

# arms: eight existing rows, then sleep, stretch, look. Still 6 columns.
arms = grow("humus-arms.png", 6, 11)
place(arms, 8, SLEEP_FRAMES["arms"])
place(arms, 9, STRETCH_FRAMES)
place(arms, 10, LOOK_FRAMES)
arms.save(SPRITES + "humus-arms.png")
```

- [ ] **Step 4: Verify the sheets are exactly the grid the renderer assumes**

The sprite renderer sizes its `<image>` as `columnsOf(form) * 64` by `animations.length * 64`. A sheet that is not exactly that **scales the whole image** and nothing in the suite catches it today (Task 3 adds the guard).

```bash
python3 -c "
from PIL import Image
for name, want in (('humus-blob',(256,192)),('humus-legs',(256,192)),('humus-arms',(384,704))):
    got = Image.open('resources/js/patyourself/sprites/%s.png' % name).size
    print(name, got, 'ok' if got == want else 'WRONG, want %s' % (want,))
"
```

Expected: three `ok`.

- [ ] **Step 5: Measure the new rows**

This script reproduces every anchor in the committed README exactly — head, face and feet, on all three forms, across all eight existing arms rows. It was validated against them before this plan was written, so a disagreement means the art moved, not that the method is wrong.

Save it to `$CLAUDE_JOB_DIR/tmp/measure.py` and run it from the repo root.

```python
"""Per-frame head/face/feet deltas, by the method in sprites/README.md.

  head  the first row carrying six or more skin-toned pixels
  face  the row with the most white eye-highlight pixels, or None when the
        eyes are shut — in which case `face` takes `head`'s own delta, the
        same convention `blink`'s closed frame and `pet` already use
  feet  the lowest opaque row

Deltas are measured against the FORM's base anchor, which is idle frame 0 —
not against the animation's own first frame.
"""

from PIL import Image

CELL = 64
SPRITES = "resources/js/patyourself/sprites/"

SHEETS = {
    "blob": ("humus-blob.png", ["idle", "blink", "sleep"]),
    "legs": ("humus-legs.png", ["idle", "blink", "sleep"]),
    "arms": ("humus-arms.png", [
        "idle", "blink", "walk", "wave", "jump", "pet", "play", "notice",
        "sleep", "stretch", "look",
    ]),
}

FRAMES = {
    "idle": 2, "blink": 2, "walk": 4, "wave": 4, "jump": 4, "pet": 4,
    "play": 6, "notice": 4, "sleep": 4, "stretch": 6, "look": 4,
}


def is_skin(px):
    """Opaque, not the black outline, not the green sprout."""
    r, g, b, a = px
    return a > 128 and (r + g + b) > 30 and not (g > r and g > b)


def is_highlight(px):
    """The near-white specks in the eyes. Nothing else on the body is this bright."""
    r, g, b, a = px
    return a > 128 and r > 240 and g > 240 and b > 240


def measure(px):
    head = face = feet = None
    best = 0

    for y in range(CELL):
        skin = white = opaque = 0

        for x in range(CELL):
            pixel = px[x, y]
            if pixel[3] > 128:
                opaque += 1
            if is_skin(pixel):
                skin += 1
            if is_highlight(pixel):
                white += 1

        if skin >= 6 and head is None:
            head = y
        if white > best:
            best, face = white, y
        if opaque:
            feet = y

    return head, (face if best else None), feet


for form, (name, rows) in SHEETS.items():
    sheet = Image.open(SPRITES + name).convert("RGBA")

    def cell(row, frame):
        return sheet.crop((
            frame * CELL, row * CELL, (frame + 1) * CELL, (row + 1) * CELL,
        )).load()

    base_head, base_face, base_feet = measure(cell(0, 0))
    print(f"\n{form}  base head={base_head} face={base_face} feet={base_feet}")

    for row, animation in enumerate(rows):
        heads, faces, feets = [], [], []

        for frame in range(FRAMES[animation]):
            head, face, feet = measure(cell(row, frame))
            heads.append(head - base_head)
            faces.append("closed" if face is None else face - base_face)
            feets.append(feet - base_feet)

        print(f"  {animation:8} head {heads}  face {faces}  feet {feets}")
```

Expected, and this is the check that the script still agrees with the art: the eight existing `arms` rows must print exactly the numbers already in `sprite-layout.ts` — `walk` head `[-2, -3, -3, -2]`, `notice` face `[-3, -8, -6, -4]`, `play` feet `[0, 0, -2, -5, -2, 0]`, and so on. `pet` prints `closed` from frame 1 and `blink` from frame 0, which is correct: those are the frames where the eye line cannot be read.

**`sleep` must print `closed` on every frame.** If it does not, the eyes open somewhere in that row and the art is wrong.

- [ ] **Step 6: Record everything in README**

Add the new rows to the sheet table at the top (sizes and row lists), a line to "Which frames were kept, and why" if any row was a selection rather than a full generation, and the measured deltas to the Offsets block in the same format as the existing entries:

```
arms   sleep    head [...]   face: closed throughout, so it takes head's own delta
                feet [...]   hand: derived
       stretch  head [...]   face [...]   neck [...]   feet [...]   hand: derived
       look     head [...]   face [...]   neck [...]   feet [...]   hand: derived
```

State in that section that `sleep`'s pose is deliberately upright, and why: a curled or lying sleeper both rotates and translates, and every per-frame anchor delta having `x = 0` is what makes a worn item one sprite instead of one per frame.

- [ ] **Step 7: Verify and commit**

Run: `npm run build && npx vitest run && npx tsc --noEmit && npm run lint`

Expected: build succeeds with the bigger PNGs bundled; JS suite unchanged from Task 1's count (nothing references the new rows yet).

```bash
git add resources/js/patyourself/sprites/
git commit -m "feat(blob): art for sleeping, stretching and looking around

Five rows: sleep on all three forms, stretch and look on arms. blob and
legs widen to four columns because sleep is four frames, with existing
pixels keeping their exact position — the shared registration is what
makes accessory alignment a small table rather than per-frame art.

The sleeping pose is upright on purpose. A curled sleeper rotates and
translates sideways, and every per-frame anchor delta having x = 0 is
the whole reason a worn item is one sprite instead of one per frame.

Nothing draws these yet."
```

---

### Task 3: The registry, the tables and both renderers

The art exists; this task makes it drawable. Note the sequencing: **`sprite-layout.test.ts`'s existing guard goes red the moment a registry entry lands and stays red until the row is declared.** That is the failing test for this task, by design.

**Files:**
- Modify: `resources/js/patyourself/companion-animations.ts:40-87`
- Modify: `resources/js/patyourself/sprite-layout.ts:68-408`
- Modify: `resources/js/patyourself/blob-renderer.tsx:735-843`
- Modify: `resources/js/patyourself/sprite-layout.test.ts`
- Modify: `resources/js/patyourself/blob-renderer.test.tsx`

**Interfaces:**
- Consumes: Task 2's sheets and README measurements.
- Produces: `'sleep' | 'stretch' | 'look'` as valid `AnimationName`s, each with a row on `arms`; `sleep` also on `blob` and `legs`. Task 4 returns these names from `ambientFor`/`selfStartedFor`.

- [ ] **Step 1: Add the three registry entries**

In `resources/js/patyourself/companion-animations.ts`, after `jump` and before `pet` (grouping the ambients together, as the file already does):

```ts
    /**
     * What Blob does through the part of the day config marks `asleep`. An
     * ambient like `idle` and `walk` — they share a channel, so only one of
     * them ever runs, and which one is `ambientFor`'s to say.
     *
     * No `autoEvery`: this is a state rather than something Blob does now and
     * then. 1fps is the slowest rate in this table, and that is the point — a
     * four-second breath is what separates sleeping from standing still, more
     * than the pose does.
     *
     * Not an ability, and there is no rung for it. Sleeping is on `blink`'s
     * side of that line: being alive rather than a skill, so it needs nothing
     * earned and announces nothing.
     */
    sleep: { frames: 4, fps: 1, loop: true, channel: 'ambient' },
    /**
     * Waking up: eligible only in the part that follows the sleeping one, and
     * fired by the same auto-timer as `blink`.
     *
     * There is no transition to hang it on. Nothing ticks the hour and the
     * clock stops while the tab is hidden, so a boundary animation would need
     * a timer of its own and would then play to an empty room at dawn. This
     * plays a few times through the morning instead, for whoever is there.
     */
    stretch: {
        frames: 6,
        fps: 8,
        loop: false,
        channel: 'ambient',
        autoEvery: [20000, 60000],
    },
    /**
     * Blob turns to look at something and turns back. Alongside `blink` in
     * every awake part, and exempt from the ladder for the same reason: no
     * rung would announce it and it claims no capability.
     *
     * In place, like every other animation here. Nothing Blob does translates
     * sideways — see `sprite-layout.ts` on why every per-frame delta has
     * `x = 0`.
     */
    look: {
        frames: 4,
        fps: 8,
        loop: false,
        channel: 'ambient',
        autoEvery: [12000, 35000],
    },
```

- [ ] **Step 2: Run the sprite layout guard and watch it fail**

Run: `npx vitest run resources/js/patyourself/sprite-layout.test.ts`

Expected: FAIL on "gives the fullest form a row for every animation the clock can play" — `expect(fullest.animations).toContain('sleep')`. This is the guard doing its job: an animation with no row is a red test by design.

- [ ] **Step 3: Declare the rows and their offsets**

In `resources/js/patyourself/sprite-layout.ts`, append `'sleep'` to the `blob` and `legs` forms' `animations` arrays, and `'sleep', 'stretch', 'look'` to `arms`. **Append — the array is the row order.**

Then add each row's offsets, using the numbers Task 2 measured and recorded in README. The conventions, all already established in this file:

- `face`'s delta is a measurement **except where the eyes are closed**, where it takes `head`'s own delta. `sleep` is closed throughout, so **every `sleep` frame's `face` delta equals its `head` delta**.
- `neck` is `face + 9` and rides the skull, so its delta is `face`'s, copied.
- `hand` is `derivedHandDelta(head, feet)` per frame — `Math.round((head + feet) / 2)`. A guard already holds the whole table to it. An anchor whose delta is zero on every frame gets **no row**.
- Every `x` component is `0`.

Worked example, for a measured `sleep` on `arms` of head `[-1, -2, -3, -2]` and feet `[0, 0, 0, 0]` — substitute your real numbers:

```ts
            // Eyes shut on all four frames, so `face` carries `head`'s own
            // delta rather than a reading that cannot be taken — the same
            // convention as `blink`'s closed frame and `pet`. `neck` follows
            // `face`, as everywhere. `hand` is the derivation:
            // round((head + feet) / 2).
            sleep: {
                head: [
                    [0, -1],
                    [0, -2],
                    [0, -3],
                    [0, -2],
                ],
                face: [
                    [0, -1],
                    [0, -2],
                    [0, -3],
                    [0, -2],
                ],
                neck: [
                    [0, -1],
                    [0, -2],
                    [0, -3],
                    [0, -2],
                ],
                hand: [
                    [0, 0],
                    [0, -1],
                    [0, -2],
                    [0, -1],
                ],
            },
```

- [ ] **Step 4: Add the two new guards**

In `resources/js/patyourself/sprite-layout.test.ts`:

```ts
import { readFileSync } from 'node:fs';

/** Which file each form's sheet actually is. Named here because `form.sheet`
 *  is a bundled URL by the time this test sees it, not a path on disk. */
const SHEET_FILES: Record<string, string> = {
    blob: 'humus-blob.png',
    legs: 'humus-legs.png',
    arms: 'humus-arms.png',
};

/** A PNG's width and height, out of the IHDR chunk that always comes first. */
function pngSize(file: string): [number, number] {
    const bytes = readFileSync(new URL(`./sprites/${file}`, import.meta.url));

    return [bytes.readUInt32BE(16), bytes.readUInt32BE(20)];
}

/**
 * The renderer sizes its `<image>` as `columnsOf(form) * CELL` by
 * `animations.length * CELL` and trusts the file to match. A sheet that does
 * not scales the entire image, and every existing assertion here stays green
 * while it happens — the same shape of failure that once put the shoes in the
 * bottom-left corner with the feet bare.
 */
it('has sheets whose pixels are exactly the grid the renderer assumes', () => {
    for (const form of FORMS) {
        expect([SHEET_FILES[form.feature], ...pngSize(SHEET_FILES[form.feature])]).toEqual([
            SHEET_FILES[form.feature],
            columnsOf(form) * CELL,
            form.animations.length * CELL,
        ]);
    }
});

/**
 * Nothing Blob does moves it sideways, and that single fact is why a worn
 * item is one sprite rather than one per frame. It has been prose in
 * docs/BLOB.md and nothing else; a sleeping pose that lay down or curled up
 * is exactly what would break it, so it is a guard now.
 */
it('never moves an anchor sideways, on any form or frame', () => {
    let checked = 0;

    for (const form of FORMS) {
        for (const [animation, anchors] of Object.entries(form.offsets ?? {})) {
            for (const [anchor, frames] of Object.entries(anchors)) {
                for (const [index, delta] of (frames ?? []).entries()) {
                    expect(delta[0], `${form.feature} ${animation} ${anchor} frame ${index}`).toBe(0);
                    checked += 1;
                }
            }
        }
    }

    // A loop that stopped finding tables would assert nothing at all.
    expect(checked).toBeGreaterThan(100);
});

/**
 * The one animation whose fallback is least acceptable: the
 * hold-the-first-idle-frame contract is fine for a wave an early form cannot
 * do, but sleep would leave a Blob three outcomes deep standing awake for
 * eight hours every night.
 */
it('lets every form sleep, not just the fullest one', () => {
    for (const form of FORMS) {
        expect(form.animations).toContain('sleep');
    }
});
```

- [ ] **Step 5: Run the layout suite and watch it pass**

Run: `npx vitest run resources/js/patyourself/sprite-layout.test.ts`

Expected: PASS, including the `derivedHandDelta` guard over the new `hand` rows. If that one fails, the `hand` numbers are not `Math.round((head + feet) / 2)` — fix the table, not the guard.

- [ ] **Step 6: Teach the SVG renderer the three poses**

The `svg` renderer is what the shared fixture defaults to, so most of the suite never sees the sprite path. It needs its own case per animation — "an entry in the registry plus one case in a renderer, that is the whole contract."

In `resources/js/patyourself/blob-renderer.tsx`, inside `bodyTransform`'s switch, before the `default`:

```ts
        case 'sleep':
            // A deeper, far slower breath than idle's: about twice the
            // amplitude at a quarter of the rate. No rotation — the sleeping
            // Blob is drawn slumped, never tipped over, which is the same
            // constraint the sprite art is briefed with.
            return {
                ...origin,
                transform: `scaleY(${[0.97, 0.95, 0.93, 0.95][frame] ?? 0.95})`,
                transitionDuration: '900ms',
            };

        case 'stretch':
            // Reaching up, then settling. It reads as length rather than
            // height because the feet never leave the floor, which is what
            // separates it from jump.
            return {
                ...origin,
                transform: `scaleY(${[0.96, 1.04, 1.06, 1.04, 1.01, 1][frame] ?? 1})`,
                transitionDuration: '120ms',
            };

        case 'look':
            // Turning to look at something, which on a creature that is
            // mostly head is a lean. Smaller than wave's tilt and level again
            // by the end, so it reads as attention rather than as a greeting.
            return {
                ...origin,
                transform: `rotate(${[0, -4, -5, 0][frame] ?? 0}deg)`,
                transitionDuration: '110ms',
            };
```

And in `eyesClosed`:

```ts
/** Which animations shut Blob's eyes, and on which frames. */
function eyesClosed(animation: AnimationName, frame: number): boolean {
    if (animation === 'blink') {
        return frame === 0;
    }

    // Pet keeps them shut throughout — a quarter of a second of contentment,
    // rather than a wink. Sleep keeps them shut for as long as it runs, which
    // is all night.
    return animation === 'pet' || animation === 'sleep';
}
```

- [ ] **Step 7: Write the renderer tests**

In `resources/js/patyourself/blob-renderer.test.tsx`, following that file's existing patterns:

```ts
/**
 * Sleep is the one animation whose eyes are shut for its whole duration, and
 * the SVG renderer is what the fixture defaults to — so this is the path most
 * of the suite actually draws.
 */
it('keeps Blob&apos;s eyes shut for every frame of sleep', () => {
    for (let frame = 0; frame < ANIMATIONS.sleep.frames; frame += 1) {
        const { container } = render(
            <svg>
                <SvgBlobRenderer
                    animation="sleep"
                    frame={frame}
                    features={['blob', 'legs', 'arms']}
                    items={[]}
                    abilities={[]}
                />
            </svg>,
        );

        expect(container.querySelector('.blob-eyes--closed')).not.toBeNull();
    }
});

/**
 * Every animation the clock can play has to become a pose. The default branch
 * holds the body still, which is right for blink and wrong for anything that
 * moves — an animation that fell through to it would simply never animate.
 */
it('gives every non-scene animation a transform of its own', () => {
    for (const name of Object.keys(ANIMATIONS) as AnimationName[]) {
        if (ANIMATIONS[name].channel === 'scene' || name === 'blink') {
            continue;
        }

        const transforms = new Set<string>();

        for (let frame = 0; frame < ANIMATIONS[name].frames; frame += 1) {
            const { container } = render(
                <svg>
                    <SvgBlobRenderer
                        animation={name}
                        frame={frame}
                        features={['blob', 'legs', 'arms']}
                        items={[]}
                        abilities={[]}
                    />
                </svg>,
            );

            transforms.add(
                container.querySelector('.blob-anim')?.getAttribute('style') ?? '',
            );
        }

        expect(transforms.size, `${name} never changes`).toBeGreaterThan(1);
    }
});
```

Check the closed-eyes class name against what `<Eyes closed>` actually renders in that file and use the real one — `.blob-eyes--closed` is the expected name, not a guess to leave unverified.

- [ ] **Step 8: Run it all**

```
npx vitest run
npx tsc --noEmit
npm run lint
npm run build
php artisan test --compact
```

Expected: all green, JS count up again.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat(blob): draw sleeping, stretching and looking around

Three registry entries, their rows on the sheets, their per-frame anchor
offsets and a case each in the SVG renderer. sleep is 1fps — the slowest
rate in the table — because a four-second breath is what separates
sleeping from standing still.

Two new guards. One holds every sheet's pixel dimensions to the grid the
renderer assumes, which nothing checked: a mis-sized sheet scales the
whole image while every existing assertion stays green. The other holds
every per-frame anchor delta to x = 0, which had been prose in BLOB.md
and is exactly what a lying-down sleeping pose would have broken.

Nothing selects sleep yet."
```

---

### Task 4: `ambientFor` and `selfStartedFor` become part-aware

The feature goes live here.

**Files:**
- Modify: `resources/js/patyourself/companion.tsx:77-101`
- Modify: `resources/js/patyourself/companion.test.tsx`

**Interfaces:**
- Consumes: `asleepAt`, `wakingAt` from Task 1; `'sleep' | 'stretch' | 'look'` from Task 3.
- Produces:
  ```ts
  export function ambientFor(companion: CompanionData, hour?: number): AnimationName;
  export function selfStartedFor(companion: CompanionData, hour?: number): AnimationName[];
  ```
  Both keep their existing single-argument call sites working: `pages/companion.tsx` and the `Companion` component call them unchanged.

- [ ] **Step 1: Write the failing tests**

In `resources/js/patyourself/companion.test.tsx`. Add the four-part room helper at the top of the file (the fixture's own room has three parts and no sunrise, so `day` would be its waking part — which is not the shape config ships):

```ts
import type { RoomPalette } from './part-of-day';

/**
 * A four-part day in the shape config ships, built locally: the shared
 * fixture's room has three parts and no sunrise, so `day` is what follows its
 * night — fine for the room's own tests, wrong for asserting when Blob wakes.
 */
function fourPartDay(): Record<string, RoomPalette> {
    return {
        sunrise: { from: 5, wall: '#F2E0D0', window: '#F0B98A', light: '#F4A15C', dim: 0.18 },
        day: { from: 8, wall: '#EFE6D6', window: '#B9D5E4', light: '#FFFFFF', dim: 0 },
        dusk: { from: 18, wall: '#E7D2BE', window: '#E9A468', light: '#E2762F', dim: 0.22 },
        night: { from: 21, wall: '#2F3A40', window: '#1A2530', light: '#2B3F6B', dim: 0.42, asleep: true },
    };
}

/** Hours that land in each part of the day above. */
const ASLEEP = 23;
const WAKING = 6;
const AWAKE = 13;
```

Then, replacing nothing — the existing `ambientFor` case stays as it is:

```ts
describe('ambientFor, on the clock', () => {
    it('sleeps through the part config marks asleep', () => {
        const data = companion({ room: fourPartDay() });

        expect(ambientFor(data, ASLEEP)).toBe('sleep');
        expect(ambientFor(data, 2)).toBe('sleep');
    });

    it('is idle or walking at every other hour', () => {
        const data = companion({ room: fourPartDay() });

        expect(ambientFor(data, WAKING)).toBe('idle');
        expect(ambientFor(data, AWAKE)).toBe('idle');
        expect(ambientFor({ ...data, abilities: ['walk'] }, AWAKE)).toBe('walk');
    });

    /** Sleeping is not a skill, so it needs nothing earned. */
    it('sleeps with nothing earned at all', () => {
        expect(
            ambientFor(companion({ room: fourPartDay(), abilities: [] }), ASLEEP),
        ).toBe('sleep');
    });

    /** Two rungs, and sleep is the first of them: it is a state of the whole
     *  creature, where walking is what an awake Blob does. */
    it('sleeps rather than walks, once walking is earned', () => {
        expect(
            ambientFor(
                companion({ room: fourPartDay(), abilities: ['walk'] }),
                ASLEEP,
            ),
        ).toBe('sleep');
    });

    /**
     * THE MIRROR GUARD. Blob's life is never a mirror: what it is doing must
     * be a function of the clock and of config, and of nothing in the record.
     * A sleeping Blob is safe because it is night; it becomes a rebuke the
     * moment it correlates with the person.
     *
     * The mutation this kills is any read of the record inside either
     * selector — a gate on log_count, a check for how long since the last
     * outcome, anything at all.
     */
    it('reads the clock and nothing about the record', () => {
        const room = fourPartDay();
        const empty = companion({ room, log_count: 1, insight_count: 0, unlocks: [] });
        const deep = companion({
            room,
            log_count: 900,
            insight_count: 50,
            stage_index: 13,
            unlocks: [unlock(), unlock({ kind: 'ability', name: 'walk' })],
            latest_unlock: unlock({ kind: 'ability', name: 'walk' }),
        });

        for (const hour of [0, WAKING, AWAKE, 19, ASLEEP]) {
            expect(ambientFor(empty, hour)).toBe(ambientFor(deep, hour));
            expect(selfStartedFor(empty, hour)).toEqual(selfStartedFor(deep, hour));
        }
    });
});

describe('selfStartedFor, on the clock', () => {
    /**
     * The subtle one. `blink` has a row on every form and fires for every
     * Blob that exists, so left alone the auto-timer opens a sleeping Blob's
     * eyes for an eighth of a second every few seconds — a sleeper with a
     * twitching eyelid, and nothing about the pose or the class names would
     * have shown it.
     */
    it('starts nothing at all while Blob is asleep', () => {
        const data = companion({
            room: fourPartDay(),
            abilities: ['walk', 'wave', 'jump'],
        });

        expect(selfStartedFor(data, ASLEEP)).toEqual([]);
        expect(selfStartedFor(data, ASLEEP)).not.toContain('blink');
    });

    it('stretches only in the part that follows sleeping', () => {
        const data = companion({ room: fourPartDay() });

        expect(selfStartedFor(data, WAKING)).toContain('stretch');
        expect(selfStartedFor(data, AWAKE)).not.toContain('stretch');
        expect(selfStartedFor(data, 19)).not.toContain('stretch');
        expect(selfStartedFor(data, ASLEEP)).not.toContain('stretch');
    });

    /** Being alive rather than a skill, both of them, so neither waits on
     *  the ladder. */
    it('blinks and looks around at every awake hour, with nothing earned', () => {
        const data = companion({ room: fourPartDay(), abilities: [] });

        for (const hour of [WAKING, AWAKE, 19]) {
            expect(selfStartedFor(data, hour)).toContain('blink');
            expect(selfStartedFor(data, hour)).toContain('look');
        }
    });

    it('still adds the earned one-shots while awake', () => {
        const data = companion({ room: fourPartDay(), abilities: ['wave'] });

        expect(selfStartedFor(data, AWAKE)).toContain('wave');
        expect(selfStartedFor(data, ASLEEP)).not.toContain('wave');
    });
});

describe('the button row', () => {
    /**
     * A Sleep button at two in the afternoon is the user putting the creature
     * down, and Blob never asks to be pressed. Neither of the other two is
     * something to ask for either: they happen or they do not.
     *
     * `actionsFor` takes no hour and must not gain one — what can be asked of
     * Blob is a question about what it has learned, not about the time. So
     * this asserts the absence once, against a Blob that has earned
     * everything the row can offer.
     */
    it('never offers sleeping, stretching or looking around', () => {
        const names = actionsFor(
            companion({ room: fourPartDay(), abilities: ['walk', 'wave', 'jump'] }),
        ).map((action) => action.animation);

        expect(names).toEqual(['pet', 'play', 'wave', 'jump']);
    });
});
```

- [ ] **Step 2: Run them and watch them fail**

Run: `npx vitest run resources/js/patyourself/companion.test.tsx`

Expected: FAIL — `ambientFor` takes one argument today, so the hour is ignored and every asleep case returns `idle`.

- [ ] **Step 3: Make the two selectors part-aware**

In `resources/js/patyourself/companion.tsx`:

```ts
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
```

And the import:

```ts
import { asleepAt, wakingAt } from '@/patyourself/part-of-day';
```

- [ ] **Step 4: Run them and watch them pass**

Run: `npx vitest run resources/js/patyourself/companion.test.tsx`

Expected: PASS, including the mirror guard.

- [ ] **Step 5: Write the two behavioural tests that need the clock driven by hand**

Still in `companion.test.tsx`. These use `vi.setSystemTime`, because `Companion` calls `ambientFor(companion)` with the default argument and there is no prop to thread an hour through.

```ts
/**
 * A reaction always wins, and hands the channel back to whatever the ambient
 * is now — which at night is sleep, not idle. This is what makes the 11pm
 * visit safe: log an outcome and Blob stirs exactly as it does at noon.
 */
it('reacts at night and goes back to sleep, not to standing', () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-09-11T23:30:00'));

    let pending: FrameRequestCallback[] = [];
    let handle = 0;

    vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback): number => {
        pending.push(callback);

        return ++handle;
    });
    vi.stubGlobal('cancelAnimationFrame', vi.fn());

    const tick = (now: number) => {
        const due = pending;
        pending = [];

        act(() => {
            for (const callback of due) {
                callback(now);
            }
        });
    };

    const { container } = render(
        <Companion companion={companion({ room: fourPartDay() })} reactTo={101} />,
    );

    tick(0);

    expect(
        container.querySelector('.blob-anim')?.getAttribute('data-animation'),
    ).toBe('notice');

    // notice is 4 frames at 8fps: 500ms finishes it and hands the channel back.
    tick(500);

    expect(
        container.querySelector('.blob-anim')?.getAttribute('data-animation'),
    ).toBe('sleep');

    vi.useRealTimers();
});

/**
 * Reduced motion holds frame 0 and never advances, which for sleep is exactly
 * right: it is a state, and a held sleeping pose is the rest state that
 * preference asks for. What must not happen is the held pose being idle.
 */
it('holds the sleeping pose under reduced motion', () => {
    reduceMotion(true);
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-09-11T23:30:00'));

    const { container } = render(
        <Companion companion={companion({ room: fourPartDay() })} />,
    );

    const blob = container.querySelector('.blob-anim');

    expect(blob?.getAttribute('data-animation')).toBe('sleep');
    expect(blob?.getAttribute('data-frame')).toBe('0');

    vi.useRealTimers();
});
```

Add `vi.useRealTimers()` to the existing `afterEach` in that file, so a failed assertion cannot leak fake timers into the next test — the same reasoning as the `__resetSpriteClock()` already there.

- [ ] **Step 6: Run the whole suite**

```
npx vitest run
npx tsc --noEmit
npm run lint
npm run build
php artisan test --compact
```

Expected: all green. If `pages/companion.test.tsx` or `dashboard.test.tsx` has gone red, the machine running the suite is in a timezone where the wall clock is inside the night part — which is a real finding, not a flake: it means one of those tests asserted `idle` against the default clock. Pin it with `vi.setSystemTime` rather than changing what Blob does.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat(blob): Blob sleeps at night, and stretches in the morning

ambientFor becomes a two-rung precedence list — sleep, then walk or
idle — keeping the single return value that is why walk and idle share
a channel. The order is a claim about the creature: sleeping is a state
of the whole of Blob, where walking is what an awake Blob does.

selfStartedFor returns nothing at all while Blob is asleep. blink has a
row on every form and fires for every Blob, so left scheduled it would
open a sleeping Blob's eyes for an eighth of a second every few
seconds. stretch is eligible only in the part that follows sleeping,
and look joins blink at every awake hour: neither is an ability, both
are being alive.

Both selectors read the hour and config, and nothing in the record. A
test holds them to it by asserting a Blob one outcome deep and one nine
hundred deep behave identically at every hour — Blob's life is never a
mirror, and it sleeps because it is night."
```

---

### Task 5: Render it and look, then say so in BLOB.md

Class-name assertions and geometry maths cannot judge a visual: the shoes once shipped into the bottom-left corner with the feet bare and 340 tests green. And Blob's frames freeze under browser automation, because the clock stops on `document.hidden` — so `data-frame` reads 0 forever and a sleep cycle cannot be eyeballed by watching it.

**Files:**
- Create then **delete**: `resources/js/patyourself/sheet-harness.test.tsx` (a dump, not a test; never committed)
- Modify: `docs/BLOB.md`
- Modify: `resources/js/patyourself/sprites/README.md` (what the render showed)

**Interfaces:**
- Consumes: everything above.
- Produces: nothing code depends on.

- [ ] **Step 1: Write the harness**

It composites **from what the component emits**, never from a reimplementation of its arithmetic — that is how D3 shipped every worn item 32px off the body with a green suite. Every worn item and both props are on Blob, because "does the hat still sit on the head in frame 3" is the actual question.

Create `resources/js/patyourself/sheet-harness.test.tsx`:

```tsx
import { render } from '@testing-library/react';
import { writeFileSync } from 'node:fs';
import { it } from 'vitest';

import { BLOB_VIEWBOX, BlobRenderer } from './blob-renderer';
import { ANIMATIONS } from './companion-animations';
import type { AnimationName } from './companion-animations';

it('dumps every frame of the new rows to sheet-harness.html', () => {
    const forms = [
        { label: 'blob', features: ['blob'] },
        { label: 'legs', features: ['blob', 'legs'] },
        { label: 'arms', features: ['blob', 'legs', 'arms'] },
    ];
    const names: AnimationName[] = ['idle', 'sleep', 'stretch', 'look'];

    let html =
        '<style>body{background:#6b6b6b;font:12px monospace;color:#f4f4f4}' +
        'svg{width:128px;height:168px;image-rendering:pixelated;' +
        'outline:1px solid rgba(255,255,255,.25)}</style>';

    for (const form of forms) {
        for (const name of names) {
            html += `<h3>${form.label} — ${name}</h3><div>`;

            for (let frame = 0; frame < ANIMATIONS[name].frames; frame += 1) {
                const { container } = render(
                    <svg viewBox={BLOB_VIEWBOX}>
                        <BlobRenderer
                            renderer="sprite"
                            animation={name}
                            frame={frame}
                            features={form.features}
                            items={[
                                { type: 'shoes', variant: null },
                                { type: 'hat', variant: null },
                                { type: 'glasses', variant: null },
                                { type: 'scarf', variant: 'coral' },
                            ]}
                            abilities={['walk', 'read', 'carry']}
                            arriving={null}
                        />
                    </svg>,
                );

                html += container.innerHTML;
            }

            html += '</div>';
        }
    }

    writeFileSync('sheet-harness.html', html);
});
```

- [ ] **Step 2: Run it and check the sheet paths resolved**

Run: `npx vitest run resources/js/patyourself/sheet-harness.test.tsx`

Then look at the `href` values in `sheet-harness.html`. Under vite they resolve to a path like `/resources/js/patyourself/sprites/humus-arms.png`, which is why the next step serves from the repository root. If they came out as something else, serve from wherever makes those paths resolve.

- [ ] **Step 3: Serve it and look**

Herd serves the main checkout and never a worktree, so serve it yourself, from the repository root:

```bash
php -S 127.0.0.1:8899 -t .
```

Open `http://127.0.0.1:8899/sheet-harness.html` and **look at every cell.** What you are checking:

- The shoes are on the feet, in every frame, on every form. Not in a corner.
- The hat is on the head and the glasses are on the eyes — including through `sleep`, where the eyes are shut, and through `stretch`, where the body is at its tallest.
- The scarf is at the throat, not across the mouth and not at the hips.
- Blob's eyes are **closed in all four `sleep` frames**.
- No frame drifts sideways or leans over.
- `blob` and `legs` draw their own sleep, rather than holding an idle frame.
- The sprout is not clipped at the top of any cell.

If anything is off by a row or two, the fix is the offset table in `sprite-layout.ts`, from the measured numbers. If anything is off by half a cell, the bug is a layer transform, and `sprites/README.md`'s closing section — "What a render caught that the tests did not" — describes that exact failure.

- [ ] **Step 4: Delete the harness**

```bash
rm resources/js/patyourself/sheet-harness.test.tsx sheet-harness.html
```

It is a dump, not a test: it asserts nothing, and a file in the suite that always passes is noise. Nothing else in the repo keeps one.

- [ ] **Step 5: Record what the render showed**

Add a short paragraph to `resources/js/patyourself/sprites/README.md`, under the existing "What a render caught that the tests did not", saying what was checked and what was found — including "nothing", if that is the answer. A record that the picture was looked at is the point.

- [ ] **Step 6: Update `docs/BLOB.md`**

It is the current-state reference and it is now wrong in five places. **Do not add this file to `CompanionVocabularyTest::sourceFiles()`** — it quotes the banned words in order to document them.

- §2, the rules table: nothing changes, but add a line under the banned-vocabulary paragraph noting that a sleeping Blob is a function of the clock alone, never of the record — the rule most at risk here, and the one a later change would break quietly.
- §5, Light: note that one part of the day also carries `asleep`, and that it is what Blob's own day keys off.
- §6, Forms: the table's Animations column becomes `idle, blink, sleep` for `blob` and `legs`, and all eleven for `arms`. Add the sheet sizes.
- §6, The animation registry: `ambient` now names `sleep` as the resting state at night and `look`/`stretch` among the self-starting one-shots. State that `blink` and `look` are exempt from the ladder because they are being alive rather than skills, and that **nothing self-starts while Blob is asleep**.
- §6, Anchors: the count rises from 15 resting positions and 148 per-frame pairs. Recount both from the table rather than adding an estimate, and note the new guard that every per-frame delta has `x = 0`.
- §9, Rulings not to reopen: add three rows — sleep is a behaviour rather than a rung (cost: a rung, permanently, and a shallow record standing awake while a deep one sleeps); the browser's clock drives Blob's day as well as the light (cost: the two splitting, and a sleeping Blob in a midday room); the sleeping pose stays upright (cost: per-frame horizontal anchors, and one sprite per item becoming one per frame).
- §11, What is not done: `stretch` and `look` fall back to idle on the `blob` and `legs` forms.

- [ ] **Step 7: Full verification, every command**

```
npm run build
php artisan test --compact
npx vitest run
npx tsc --noEmit
vendor/bin/pint --dirty --format agent
npm run lint
```

Expected, against the baseline of 1000 PHP / 6319 assertions / 442 JS / 0 tsc: PHP up by at least 2, JS up by at least 20, tsc still silent, Pint and lint clean, build succeeding. **Report the actual numbers.** A count that went down means a test was lost.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "docs(blob): record the day Blob now has

BLOB.md is the current-state reference and was wrong in five places:
the form table's animation lists, the anchor counts, what the ambient
channel holds, the part of day carrying a bedtime, and three rulings
that should not be reopened — sleep being a behaviour rather than a
rung, the browser's clock driving Blob's day as well as the room's
light, and the sleeping pose staying upright.

sprites/README.md records what the render showed, because class-name
assertions and geometry maths cannot judge a visual."
```

---

## Self-Review

**Spec coverage.** Every section of the spec maps to a task:

| Spec section | Task |
| --- | --- |
| Sleep is a behaviour, not an ability | 3 (no rung exists to add; the registry docblock states it), 4 (`abilities: []` sleeps) |
| Precedence list inside `ambientFor` | 4 |
| `asleep` is config's to say | 1 |
| Nothing self-starts while asleep | 4 |
| Waking is a morning-weighted one-shot | 1 (`wakingAt`), 3 (registry), 4 (eligibility) |
| Pottering is `look`, in place | 3, 4 |
| The browser's clock | 4 (default argument), and untouched elsewhere by design |
| The sleeping pose stays upright | 2 (the brief), 3 (the `x = 0` guard) |
| Registry entries | 3 |
| `part-of-day.ts` and the cycle | 1 |
| The art, sheet arithmetic, anchors | 2, 3 |
| Every error-handling row | 1 (`part-of-day.test.ts`), 3 (the form fallback is existing behaviour, guarded by "lets every form sleep") |
| Every testing row | 1, 3, 4 |
| Render it and look | 5 |
| `sourceFiles()` | 1 |

Two spec lines deliberately need no task: `prefers-reduced-motion` and the reaction channel both work without new code, and Task 4 asserts each of them rather than implementing anything.

**Type consistency.** `asleepAt(hour, room)` and `wakingAt(hour, room)` take the same argument order as `partOfDay(hour, room)` throughout. `RoomPalette` is declared once, in Task 1, and imported everywhere after. `ambientFor(companion, hour?)` and `selfStartedFor(companion, hour?)` keep `companion` first, so every existing call site is unchanged. `AnimationName` gains exactly `'sleep' | 'stretch' | 'look'`, spelled identically in Tasks 3, 4 and 5.

**One number this plan cannot pin.** Task 3's offset tables use the numbers Task 2 measures; the worked example is labelled as one and says to substitute. That is not a placeholder — the measurement script is given in full and validated against the committed art, so the numbers are produced mechanically rather than invented.
