# Blob D1 — The Visible Blob Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the gap between what Blob says about itself and what Blob is — the five announced abilities become visible, the ladder stops ending, and the room keeps filling.

**Architecture:** Three of the four tasks are entries in registries that already exist and are already read — `ABILITIES` in the renderer, `ROOM_OBJECTS` in the room, `ANIMATIONS` in the clock. Only one task changes behaviour: the auto-timer must stop firing ability animations for a Blob that has not unlocked them. The endless tail is computed in `CompanionResolver` from the ladder it already walks, with every value derived from the rung's index so history cannot change wording between two reads.

**Tech Stack:** Laravel 13, PHP 8.4, React 19, PHPUnit 12, Vitest 4. **No new dependencies.**

## Global Constraints

- **Blob tracks the work, not the user.** A `failed` outcome advances it exactly as far as a `completed` one.
- **Nothing decays, regresses or expires.**
- **Never name what is not yet unlocked** — no locked slots, no remaining counts, no previews, anywhere, including the tail. Naming it turns Blob into a checklist, and a checklist is a thing to be behind on.
- **Copy rules:** sentence case, one or two sentences, no exclamation marks, never congratulating, no second person keeping score. Say what Blob can now do — never how well the user is performing.
- **The item type cap is four, forever.** `CompanionLadderTest` asserts it. The tail recolours; it never introduces a fifth type.
- **No new dependencies. No new table, no migration, no stored counter.** `CompanionResolver` is a pure read over the record and stays one.
- **Tail rungs must be stable.** The unlock list is history. Everything about a tail rung — type, variant, message, room object — is derived from its index, never from randomness. Two reads of the same record must produce byte-identical rungs.
- Pint after PHP changes: `vendor/bin/pint --dirty --format agent`.
- No route changes in this plan, so no `wayfinder:generate`.
- Herd serves the app at https://patyourself.test. **Never run `php artisan serve`.**
- Tests are PHPUnit, not Pest: `php artisan test --compact --filter=Name`. JS: `npx vitest run`.

### Traps this codebase has already sprung on people

- **Laravel Boost's `database-schema` tool reads a stale database.** Use `database/migrations/` as the source of truth. (No schema work in this plan, but do not be misled if you look.)
- **Tests run on SQLite; production is MySQL.** A green suite is not evidence raw SQL works in production. Nothing here needs raw SQL — keep it that way.
- **Every feature test that renders a view must call `$this->withoutVite()` in `setUp()`.** `public/build` is gitignored and the suite must stay green on an unbuilt checkout.
- `prefers-reduced-motion` is already handled in `use-sprite-clock.ts` and must stay handled: nothing self-starts under it.

### Baseline

`main` at `2299ce4`: **783 PHP tests, 214 JS tests passing. Pint clean. Exactly 1 TypeScript error** (`catch-up.tsx:132`, pre-existing and out of scope). That count must not grow.

---

### Task 1: The ladder stops ending

The last authored rung is `insights: 9`. Past it the resolver computes rungs at a fixed cadence, each recolouring an item type Blob already owns.

**Files:**
- Modify: `config/companion.php` (add a `tail` block)
- Modify: `app/Services/Companion/CompanionResolver.php`
- Test: `tests/Feature/Companion/CompanionTailTest.php`

**Interfaces:**
- Produces: tail entries in `CompanionState::$unlocks`, shaped exactly like authored ones — `kind: 'item'`, a `name` from `item_types`, a `variant`, an optional `room_object`, a `message`, an `unlocked_at`. Every existing consumer already handles this shape; nothing downstream changes.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Companion;

use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Companion\CompanionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanionTailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Gives the user enough insight events to satisfy the authored ladder and
     * then some. An insight is a concluded experiment, a started version, a
     * chain correction or a reflection — concluded experiments are the cheapest
     * to make in bulk.
     */
    private function userWithInsights(int $count): User
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();

        for ($i = 0; $i < $count; $i++) {
            Strategy::factory()->for($loop, 'intention')->create([
                'version' => $i + 1,
                'verdict' => 'worked',
            ]);
        }

        return $user;
    }

    public function test_the_ladder_continues_past_its_last_authored_rung(): void
    {
        $authored = count(config('companion.ladder'));
        $every = (int) config('companion.tail.every');

        // The last authored rung sits at insights: 9, so the first tail rung
        // needs `every` more than that.
        $user = $this->userWithInsights(9 + $every);

        $state = app(CompanionResolver::class)->forUser($user);

        $this->assertCount($authored + 1, $state->unlocks);
        $this->assertSame('item', $state->unlocks[$authored]['kind']);
        $this->assertNotNull($state->unlocks[$authored]['variant']);
    }

    public function test_a_tail_rung_is_identical_across_two_reads(): void
    {
        $user = $this->userWithInsights(9 + (int) config('companion.tail.every'));
        $resolver = app(CompanionResolver::class);

        $first = $resolver->forUser($user)->unlocks;
        $second = $resolver->forUser($user)->unlocks;

        // History cannot reword itself between two reads of the same record.
        $this->assertSame($first, $second);
    }

    public function test_the_tail_never_introduces_a_fifth_item_type(): void
    {
        $types = config('companion.item_types');
        $user = $this->userWithInsights(60);

        $state = app(CompanionResolver::class)->forUser($user);

        foreach ($state->unlocks as $unlock) {
            if ($unlock['kind'] === 'item') {
                $this->assertContains($unlock['name'], $types);
            }
        }
    }

    public function test_the_variant_palette_wraps_rather_than_running_out(): void
    {
        $variants = config('companion.tail.variants');
        $every = (int) config('companion.tail.every');

        // Enough rungs to walk past the end of the palette at least once.
        $user = $this->userWithInsights(9 + $every * (count($variants) + 2));

        $state = app(CompanionResolver::class)->forUser($user);
        $tail = array_slice($state->unlocks, count(config('companion.ladder')));

        $this->assertGreaterThan(count($variants), count($tail));
        foreach ($tail as $unlock) {
            $this->assertContains($unlock['variant'], $variants);
        }
    }

    public function test_the_tail_does_not_start_before_the_authored_ladder_is_finished(): void
    {
        // Five insights satisfies some authored rungs but not the last.
        $user = $this->userWithInsights(5);

        $state = app(CompanionResolver::class)->forUser($user);

        $this->assertLessThan(count(config('companion.ladder')), count($state->unlocks));
    }

    public function test_an_absent_tail_block_ends_the_ladder_where_it_ends_today(): void
    {
        config(['companion.tail' => []]);
        $user = $this->userWithInsights(60);

        $state = app(CompanionResolver::class)->forUser($user);

        $this->assertCount(count(config('companion.ladder')), $state->unlocks);
    }

    public function test_a_room_object_arrives_on_the_tail_at_its_own_cadence(): void
    {
        $every = (int) config('companion.tail.every');
        $roomEvery = (int) config('companion.tail.room_every');

        $user = $this->userWithInsights(9 + $every * $roomEvery);

        $state = app(CompanionResolver::class)->forUser($user);
        $tail = array_slice($state->unlocks, count(config('companion.ladder')));

        $this->assertNotNull(end($tail)['room_object']);
        // And the rungs before it did not each bring one.
        $this->assertNull($tail[0]['room_object']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=CompanionTailTest`
Expected: FAIL — the ladder stops at its authored length, so the first test finds `$authored` unlocks rather than `$authored + 1`.

- [ ] **Step 3: Add the tail block to config**

Append to `config/companion.php`, after `ladder`:

```php
    /*
    |--------------------------------------------------------------------------
    | The tail
    |--------------------------------------------------------------------------
    |
    | What happens after the last authored rung. Blob does not finish: it keeps
    | receiving recolours of things it already owns, at a slower cadence than
    | the authored ladder, forever.
    |
    | A recolour rather than a fifth item type, because the four-type cap is
    | permanent — see `item_types` above. This is the case that cap was written
    | for.
    |
    | Everything here is walked BY INDEX and never randomly. A tail rung is
    | history the moment it is earned, and history cannot reword itself between
    | two reads of the same record.
    |
    |   every       further insights each tail rung costs
    |   variants    colour names, walked in order and wrapped
    |   room_every  a room object arrives every Nth tail rung, not every rung
    |   room_objects  what arrives, walked in order and wrapped
    |   messages    authored lines; {type} and {variant} are substituted
    |
    */

    'tail' => [
        'every' => 3,

        'variants' => ['coral', 'moss', 'slate', 'amber', 'plum', 'rust'],

        'room_every' => 3,

        'room_objects' => ['rug', 'lamp', 'plant', 'stool'],

        // Copy rules as everywhere else: sentence case, no exclamation marks,
        // never congratulating. These describe Blob, not the person reading.
        'messages' => [
            'Blob has another {type}, in {variant}. It keeps the old one, folded somewhere.',
            'A {variant} {type} turned up. Blob has opinions about the colour and is not sharing them.',
            'Blob swapped to the {variant} {type} this morning. No occasion.',
            'There is a {variant} {type} now. Blob wore it immediately and has not mentioned it.',
            'The {variant} {type} arrived. Blob tried it on twice before settling.',
        ],
    ],
```

- [ ] **Step 4: Continue the walk in the resolver**

In `CompanionResolver::forUser()`, after the authored `foreach` completes, the tail only begins when **every** authored rung is satisfied. Add before constructing the state:

```php
        // The tail begins only once the authored ladder is finished. A partial
        // walk means the record has not reached the end yet, and the tail is
        // what happens after the end.
        if (count($unlocks) === count($ladder)) {
            $unlocks = [...$unlocks, ...$this->tailUnlocks($insights)];
        }
```

and add the two methods:

```php
    /**
     * Rungs past the last authored one, recolouring what Blob already owns.
     *
     * Every value is derived from the rung's index — which type, which variant,
     * which message, whether a room object comes with it. Nothing is random,
     * because a rung is history the moment it is earned and history cannot
     * reword itself between two reads of the same record.
     *
     * @param  list<CarbonImmutable>  $insights
     * @return list<array<string, mixed>>
     */
    private function tailUnlocks(array $insights): array
    {
        /** @var array<string, mixed> $tail */
        $tail = (array) config('companion.tail', []);

        $every = (int) ($tail['every'] ?? 0);
        $variants = array_values((array) ($tail['variants'] ?? []));
        $messages = array_values((array) ($tail['messages'] ?? []));
        $roomEvery = (int) ($tail['room_every'] ?? 0);
        $roomObjects = array_values((array) ($tail['room_objects'] ?? []));
        $types = array_values((array) config('companion.item_types', []));

        // An absent or incomplete tail block simply ends the ladder, which is
        // what happened before this existed.
        if ($every < 1 || $variants === [] || $messages === [] || $types === []) {
            return [];
        }

        $base = $this->lastAuthoredInsightThreshold();
        $unlocks = [];

        for ($rung = 1; ; $rung++) {
            $at = $base + $rung * $every;

            if (count($insights) < $at) {
                break;
            }

            $index = $rung - 1;
            $type = $types[$index % count($types)];
            $variant = $variants[$index % count($variants)];

            $bringsObject = $roomEvery > 0
                && $roomObjects !== []
                && $rung % $roomEvery === 0;

            $unlocks[] = [
                'kind' => 'item',
                'name' => $type,
                'variant' => $variant,
                'room_object' => $bringsObject
                    ? $roomObjects[(intdiv($rung, $roomEvery) - 1) % count($roomObjects)]
                    : null,
                'message' => str_replace(
                    ['{type}', '{variant}'],
                    [$type, $variant],
                    $messages[$index % count($messages)],
                ),
                'unlocked_at' => $insights[$at - 1]->toIso8601String(),
            ];
        }

        return $unlocks;
    }

    /**
     * The insight count the last authored insight rung sits at — where the tail
     * starts counting from. Read from the ladder rather than hardcoded, so
     * appending an authored rung still moves the tail along behind it.
     */
    private function lastAuthoredInsightThreshold(): int
    {
        /** @var array<int, array<string, mixed>> $ladder */
        $ladder = config('companion.ladder', []);

        $thresholds = array_map(
            static fn (array $entry): int => (int) ($entry['at'] ?? 0),
            array_filter(
                $ladder,
                static fn (array $entry): bool => ($entry['trigger'] ?? 'logs') === 'insights',
            ),
        );

        return $thresholds === [] ? 0 : max($thresholds);
    }
```

- [ ] **Step 5: Run the tests**

```bash
php artisan test --compact --filter=CompanionTailTest
php artisan test --compact --filter=Companion
```

Expected: PASS. `CompanionLadderTest` must still be green — the tail introduces no fifth item type.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/companion.php app/Services/Companion/CompanionResolver.php tests/Feature/Companion
git commit -m "feat(blob): the ladder stops ending"
```

---

### Task 2: Two abilities you can see

`ABILITIES` is an empty registry with a designed seam: `BlobRenderer` already maps over it and renders the result at `blob-renderer.tsx:272`. This task fills it for the two abilities that need a prop rather than a pose.

**Files:**
- Modify: `resources/js/patyourself/blob-renderer.tsx`
- Test: `resources/js/patyourself/blob-renderer.test.tsx`

**Interfaces:**
- Consumes: `AbilitySpec { extra?: (colour: string) => ReactNode }`, already declared.
- Produces: nothing new. `abilities: string[]` already flows from `CompanionData`.

- [ ] **Step 1: Read the geometry first**

Before drawing anything, read `blob-renderer.tsx` in full and note the constants the body uses — `BODY`, `BODY_COLOUR`, `LEG_LENGTH` — and the coordinate space they sit in. Extras render **inside** the transformed group, so they follow the body rather than sliding off it. Your props must be positioned in that same space and must look right at 32px, which is the size the dashboard corner draws.

- [ ] **Step 2: Write the failing test**

```tsx
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { SvgBlobRenderer } from './blob-renderer';

function draw(abilities: string[]) {
    return render(
        <svg>
            <SvgBlobRenderer
                animation="idle"
                frame={0}
                features={['blob', 'legs']}
                items={[]}
                abilities={abilities}
            />
        </svg>,
    );
}

describe('ability props', () => {
    it('draws a book once Blob can read', () => {
        const { container } = draw(['read']);

        expect(container.querySelector('.blob-ability--read')).not.toBeNull();
    });

    it('draws nothing for read before it is unlocked', () => {
        const { container } = draw([]);

        expect(container.querySelector('.blob-ability--read')).toBeNull();
    });

    it('draws something to carry once Blob can carry', () => {
        const { container } = draw(['carry']);

        expect(container.querySelector('.blob-ability--carry')).not.toBeNull();
    });

    it('renders the body and no prop for an ability nothing draws', () => {
        // `wave` is a pose, not a prop: it has frames, not an ABILITIES entry.
        // The contract is that this cannot break the screen.
        const { container } = draw(['wave']);

        expect(container.querySelector('.blob-anim')).not.toBeNull();
        expect(container.querySelector('[class*="blob-ability--"]')).toBeNull();
    });
});
```

Add these to the existing `blob-renderer.test.tsx` rather than creating a second file, following its existing setup.

- [ ] **Step 3: Run it to verify it fails**

Run: `npx vitest run resources/js/patyourself/blob-renderer.test.tsx`
Expected: FAIL — `ABILITIES` is empty, so no `.blob-ability--read` node exists.

- [ ] **Step 4: Fill the registry**

Replace `const ABILITIES: Record<string, AbilitySpec> = {};` with two entries. Each `extra` returns a `<g>` carrying `className="blob-ability blob-ability--<name>"` so the tests and any future styling have a handle.

```tsx
const ABILITIES: Record<string, AbilitySpec> = {
    /**
     * A book, held. What Blob reads is unclear, but it holds the page the
     * right way up — the ladder's words, so the drawing has to agree.
     */
    read: {
        extra: () => (
            <g className="blob-ability blob-ability--read">
                {/* Two leaves and a spine, held low and to Blob's left so it
                    reads as being looked at rather than carried. */}
                <rect x={-19} y={4} width={9} height={7} rx={1} fill="#C25B4A" />
                <rect x={-10} y={4} width={9} height={7} rx={1} fill="#D6805F" />
                <rect x={-10.5} y={4} width={1} height={7} fill="#8F3F33" />
            </g>
        ),
    },

    /**
     * Something to carry. The ladder says Blob "has not settled on what", so
     * this is deliberately a plain shape rather than a recognisable object.
     */
    carry: {
        extra: () => (
            <g className="blob-ability blob-ability--carry">
                {/* Opposite side to the book, so a Blob that both reads and
                    carries is not holding two things in one hand. */}
                <rect x={11} y={3} width={9} height={9} rx={2} fill="#D4942E" />
            </g>
        ),
    },
};
```

**These coordinates assume the body spans roughly x −14…14** (the legs sit at x −11 and x 4, seven wide). **Read `BODY`, `BODY_COLOUR` and `LEG_LENGTH` first and nudge the numbers** so both props sit against the body rather than floating off it — the tests assert the class name, not the geometry, so you are free to move them. Keep the flat style the rest of the file uses: plain `rect`/`circle`/`path`, explicit hex fills, no gradients. Reuse colours from the item dictionary in the same file rather than inventing new ones; the two above are taken from the bookshelf's palette.

Both must still read at 32px, which is the size the dashboard corner draws. A Blob with both abilities renders both — check they do not collide.

- [ ] **Step 5: Run the tests and commit**

```bash
npx vitest run
npx tsc --noEmit
git add resources/js/patyourself/blob-renderer.tsx resources/js/patyourself/blob-renderer.test.tsx
git commit -m "feat(blob): reading and carrying become visible"
```

`npx tsc --noEmit` must still report exactly 1 error (`catch-up.tsx:132`).

---

### Task 3: Two abilities Blob performs by itself

This is the one task in D1 that changes behaviour rather than adding data. `wave` and `jump` become animations that self-start — and the auto-timer must stop firing them for a Blob that has not unlocked them.

**Files:**
- Modify: `resources/js/patyourself/companion-animations.ts`
- Modify: `resources/js/hooks/use-sprite-clock.ts`
- Modify: `resources/js/patyourself/companion.tsx` (a selector helper)
- Modify: `resources/js/pages/companion.tsx` and `resources/js/pages/dashboard.tsx` (pass it)
- Test: `resources/js/hooks/use-sprite-clock.test.ts`, `resources/js/patyourself/companion.test.tsx`

**Interfaces:**
- Produces: `useSpriteClock(ambient?: AnimationName, selfStarted?: readonly AnimationName[]): SpriteClock` — the second parameter names which self-starting animations are allowed to fire. Defaults to `['blink']`.
- Produces: `selfStartedFor(companion: CompanionData): AnimationName[]` in `companion.tsx`.

- [ ] **Step 1: Add the two animations**

In `companion-animations.ts`, add to `ANIMATIONS`:

```ts
    wave: {
        frames: 4,
        fps: 8,
        loop: false,
        channel: 'ambient',
        autoEvery: [14000, 30000],
    },
    jump: {
        frames: 4,
        fps: 8,
        loop: false,
        channel: 'ambient',
        autoEvery: [20000, 45000],
    },
```

They sit on the **ambient** channel, like `blink`: these are things Blob does by itself. The `reaction` channel stays for things arriving from outside Blob — `pet` and `play`.

While you are in this file, **fix the stale comment on `autoEvery`**: it says "Read by the auto-timer, which is not wired up yet." The auto-timer has been wired up since `use-sprite-clock.ts:188`. Say what it actually does.

- [ ] **Step 2: Write the failing gating test**

In `resources/js/hooks/use-sprite-clock.test.ts`, following its existing fake-timer setup:

Follow the existing blink test at `use-sprite-clock.test.ts:164` exactly — fake timers for `setTimeout` only, `Math.random` pinned to 0 so every interval lands on its minimum, and the file's own `tick(now)` helper to drive frames:

```ts
it('never self-starts an ability the Blob has not unlocked', () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    vi.spyOn(Math, 'random').mockReturnValue(0);

    const { result } = renderHook(() => useSpriteClock('idle', ['blink']));

    tick(0);

    // wave's autoEvery is [14000, 30000]; random 0 puts its first firing at
    // 14000, well inside this window.
    act(() => {
        vi.advanceTimersByTime(14_001);
    });
    tick(2000);

    expect(result.current.animation).not.toBe('wave');

    vi.useRealTimers();
});

it('self-starts an ability the Blob has earned', () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    vi.spyOn(Math, 'random').mockReturnValue(0);

    const { result } = renderHook(() =>
        useSpriteClock('idle', ['blink', 'wave']),
    );

    tick(0);

    // blink reschedules itself every 4000, so several have already come and
    // gone by here; wave fires last, at 14000, and owns the channel.
    act(() => {
        vi.advanceTimersByTime(14_001);
    });
    tick(2000);

    expect(result.current.animation).toBe('wave');

    vi.useRealTimers();
});
```

The interleaving of blink's repeats against wave's first firing is reasoned above, not measured. **Run it RED before implementing** — if the timings interleave differently than this assumes, adjust the constants rather than the assertions. The properties being pinned are "an unearned ability never fires" and "an earned one does"; the exact millisecond is not the point.

- [ ] **Step 3: Run it to verify it fails**

Run: `npx vitest run resources/js/hooks/use-sprite-clock.test.ts`
Expected: FAIL — the hook takes one argument, so the second is ignored and `wave` self-starts for everyone.

- [ ] **Step 4: Gate the auto-timer**

Change the signature:

```ts
export function useSpriteClock(
    ambient: AnimationName = 'idle',
    selfStarted: readonly AnimationName[] = ['blink'],
): SpriteClock {
```

and in the auto-timer's loop:

```ts
        for (const [name, spec] of Object.entries(ANIMATIONS)) {
            // Only what this Blob has actually unlocked. Without this, a Blob
            // that cannot wave waves — the ladder would be announcing an
            // ability the body already had.
            if (
                spec.channel === 'ambient' &&
                'autoEvery' in spec &&
                selfStarted.includes(name as AnimationName)
            ) {
                schedule(name as AnimationName, spec.autoEvery);
            }
        }
```

Add `selfStarted` to that effect's dependency array. It is an array, so callers must not build it inline on every render — the selector in the next step returns a stable value for a given companion, and the effect should depend on a joined string rather than the array identity if that proves noisy. Use your judgement and say what you chose.

**`blink` is not an ability.** It is in the default so every Blob keeps blinking, including one with nothing unlocked.

- [ ] **Step 5: Add the selector and pass it**

In `resources/js/patyourself/companion.tsx`, beside `ambientFor`:

```ts
/**
 * Which self-starting animations this Blob is allowed to fire.
 *
 * `blink` always: it is not an ability, it is being alive. Everything else has
 * to have been earned, or the body would be doing things the ladder has not
 * announced yet.
 */
export function selfStartedFor(companion: CompanionData): AnimationName[] {
    const earned = companion.abilities.filter(
        (ability): ability is AnimationName =>
            ability in ANIMATIONS &&
            ANIMATIONS[ability as AnimationName].channel === 'ambient' &&
            'autoEvery' in ANIMATIONS[ability as AnimationName],
    );

    return ['blink', ...earned];
}
```

Pass it at both call sites — `resources/js/pages/companion.tsx` and wherever the dashboard corner builds its clock (`resources/js/patyourself/companion.tsx`'s `Companion`, if that is where the clock lives; read it and follow).

- [ ] **Step 6: Test the selector**

In `companion.test.tsx`, using the existing `companion.fixture.ts`:

The fixture's factory is `companion(overrides)` — import it from `./companion.fixture`, as the other tests in this file already do:

```ts
describe('selfStartedFor', () => {
    it('lets every Blob blink, and nothing else, before anything is earned', () => {
        expect(selfStartedFor(companion({ abilities: [] }))).toEqual(['blink']);
    });

    it('adds an ability that has a self-starting animation', () => {
        expect(selfStartedFor(companion({ abilities: ['wave'] }))).toContain('wave');
    });

    it('ignores an ability that has no animation to start', () => {
        // `carry` is a prop, not a pose: it draws, but it never plays.
        expect(selfStartedFor(companion({ abilities: ['carry'] }))).toEqual(['blink']);
    });

    it('ignores `walk`, which is the ambient rather than a one-shot', () => {
        expect(selfStartedFor(companion({ abilities: ['walk'] }))).toEqual(['blink']);
    });
});
```

`selfStartedFor` reads `ANIMATIONS`, so `companion.tsx` needs to import it from `./companion-animations` if it does not already.

- [ ] **Step 7: Run everything and commit**

```bash
npx vitest run
npx tsc --noEmit
git add resources/js/patyourself/companion-animations.ts resources/js/hooks/use-sprite-clock.ts resources/js/patyourself/companion.tsx resources/js/pages tests
git commit -m "feat(blob): waving and jumping, but only once earned"
```

---

### Task 4: The room keeps filling

`roomObject` exists and exactly one rung in twelve uses it. This task gives more authored rungs an object and draws the four the tail can place.

**Files:**
- Modify: `config/companion.php` (add `roomObject` to three authored rungs)
- Modify: `resources/js/patyourself/companion-room.tsx` (four `ROOM_OBJECTS` entries)
- Test: `resources/js/patyourself/companion-room.test.tsx`

**Interfaces:**
- Consumes: `RoomObjectSpec { render: (palette: RoomPalette) => ReactNode }`, already declared, and `companion.room_objects: string[]`, already flowing.

- [ ] **Step 1: Give three authored rungs an object**

In `config/companion.php`, add `'roomObject' => …` to these existing entries, leaving everything else about them untouched:

- `insights: 5` (`wave`) → `'rug'`
- `insights: 7` (`jump`) → `'lamp'`
- `insights: 9` (`carry`) → `'plant'`

`insights: 3` keeps its `bookshelf`. The fourth tail object, `stool`, has no authored rung and arrives only from the tail.

- [ ] **Step 2: Write the failing test**

`companion-room.test.tsx` already has a `room(overrides, hour)` helper that renders `CompanionRoom` with the fixture and returns the container. Use it:

```tsx
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
```

**The `bookshelf` entry currently has no class name** — add `className="room-object room-object--bookshelf"` to its outer `<g>` so all five are addressable the same way, and give each new entry the same treatment. Without that, the third test can never reach five.

- [ ] **Step 3: Run it to verify it fails**

Run: `npx vitest run resources/js/patyourself/companion-room.test.tsx`
Expected: FAIL — `ROOM_OBJECTS` knows only `bookshelf`, and even that has no class.

- [ ] **Step 4: Draw the four objects**

Add `rug`, `lamp`, `plant` and `stool` to `ROOM_OBJECTS`, following `bookshelf` exactly: flat geometry, explicit hex fills, a fixed position, and a `className` as above.

**Positions are fixed and must not overlap.** `bookshelf` occupies roughly x −64…−34, y 10…52. Read the room's viewBox and the floor line before placing anything, and lay the four out so that a room holding all five reads as a room rather than as a pile. The room is small — prefer a few well-placed objects over detail that vanishes at the rendered size.

`render` receives the `RoomPalette`, so an object may tint itself to the part of the day. `bookshelf` ignores it; use it only where it genuinely helps (a lamp reading differently at night is the obvious case).

- [ ] **Step 5: Run everything and commit**

```bash
npx vitest run
npx tsc --noEmit
php artisan test --compact --filter=Companion
vendor/bin/pint --dirty --format agent
git add config/companion.php resources/js/patyourself/companion-room.tsx resources/js/patyourself/companion-room.test.tsx
git commit -m "feat(blob): the room keeps filling"
```

---

## D1 self-review checklist

- [ ] `php artisan test --compact` — green, 783 baseline plus the new tests
- [ ] `npx vitest run` — green, 214 baseline plus the new tests
- [ ] `npx tsc --noEmit` — still exactly 1 error (`catch-up.tsx:132`)
- [ ] `vendor/bin/pint --dirty --format agent` — clean
- [ ] A Blob with `wave` unlocked eventually waves; a Blob without it never does, however long you watch
- [ ] Every Blob still blinks, including one with nothing unlocked
- [ ] Nothing anywhere names an unlock that has not happened — no locked slots, no counts, no previews
- [ ] Two reads of the same record produce byte-identical tail rungs
- [ ] `CompanionLadderTest` still passes: no fifth item type
