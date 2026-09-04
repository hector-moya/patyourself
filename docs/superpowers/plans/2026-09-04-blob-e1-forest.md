# Blob E1 — The Forest Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Blob stands in a pixel-art forest at its own camera angle, lit by four times of day, with trees that move.

**Architecture:** A scene is a stage picked from the record, exactly as a body form is. Today's room becomes one scene of two rather than the only thing the compositor knows. Light is one overlay drawn last, over Blob as well as the scene, so four times of day cost four config entries rather than four copies of every asset.

**Tech Stack:** Laravel 13, Inertia v3, React 19, Vitest, PHPUnit. Art from Pixel Lab, committed as PNGs.

**Spec:** `docs/superpowers/specs/2026-09-04-blob-phase-e-design.md`

## Global Constraints

- **Never name or preview anything not yet reached**, in any surface. The app states no plan.
- **Blob's life is never a mirror held up to the reader.** Copy says what Blob did, never how the person is doing.
- **Copy rules:** sentence case, one or two sentences, no exclamation marks, never congratulating, no second person keeping score.
- **Banned vocabulary** in companion source files, comments included: streak, congratulation, well done, completion rate, percent, points, level up, lonely, hungry, misses you, neglect, cooldown. **"points" is a substring trap** — "appoints"/"disappoints" trip it. Every new source file goes into `CompanionVocabularyTest::sourceFiles()`.
- **`CompanionResolver` stays a pure read.** No stored scene, no new table, no migration.
- **Nothing regresses.** An established record must resolve indoors and keep every earned room object visible.
- **No easing in sprite mode.** No `transition` on anything the sprite renderer produces.
- **One animation loop for the whole app.** Foliage subscribes to `use-sprite-clock`; it does not start its own. Phase C's "exactly one" test must keep passing.
- Exactly one pre-existing TypeScript error is permitted, at `catch-up.tsx:132`. **That count must not grow.**
- Tests are PHPUnit, not Pest: `php artisan test --compact --filter=Name`, `npx vitest run`.
- Run `vendor/bin/pint --dirty --format agent` after touching PHP. Never `pint --test`.
- Every feature test that renders a view needs `$this->withoutVite()` in `setUp()`.
- **Prove every new guard goes red before trusting it.** Phase D shipped four defects past a green suite; if you cannot name the mutation that turns a test red, the test is decoration.

---

### Task 1: Generate and commit the forest art

**Executed by the orchestrator, not a subagent** — it needs Pixel Lab MCP access and visual judgement.

**Files:**
- Create: `resources/js/patyourself/scenes/forest-sunrise.png`, `forest-day.png`, `forest-dusk.png`, `forest-night.png` (144×114 each)
- Create: `resources/js/patyourself/scenes/foliage-*.png` (one sheet per animated tree)
- Create: `resources/js/patyourself/scenes/README.md`

**Interfaces:**
- Consumes: nothing.
- Produces: four backdrops on the room's exact 144×114 grid, foliage sheets on a uniform cell grid, and measured constants (each foliage layer's position and frame count) that Task 4 and Task 5 hard-code.

**Why 144×114.** Blob's sheet maps one sprite pixel to one viewBox unit (`CELL` is 64 and spans 64 units). The room's viewBox is 144×114 units. A backdrop at any other resolution puts the scene and the character on different pixel grids, and Blob reads as pasted on. It also lands under `create_image_pro`'s 170px threshold, which returns four candidates per call instead of one.

- [ ] **Step 1: Generate sunrise first and check the angle before anything else**

`create_image_pro`, `no_background: false`, 144×114, with Blob's own sheet as `style_image_url` and `style_copy: ["outline", "shading", "detail"]` — not `color_palette`, or the forest comes out earth-brown like the character.

`create_image_pro` has **no `view` parameter.** The camera angle lives in the description and can only be judged by eye. The whole phase exists to end the mismatch between a side-on room and a `low top-down` character, so **look at the angle before generating the other three.** If the ground reads as a flat wall rather than receding, re-roll the description rather than continuing.

- [ ] **Step 2: Generate the other three from sunrise as the style reference**

Pass the chosen sunrise candidate as `style_image_url` for day, dusk and night so the four agree on palette and density. Describing the same forest four times and hoping is what failed repeatedly in Phase D; a reference is what worked.

Only the sky, the sun's position and the light change. The trees, the ground and the horizon line must not move between the four, because the scene cross-fades between them at no point — it swaps.

- [ ] **Step 3: Cut the foliage layers**

Two or three trees, each its own sheet on a uniform cell grid, one row per animation with frames left to right — the same sheet format as Blob, so the same clock reads them. `create_map_object` accepts `view: "low top-down"` and a `background_image` for style matching; `animate_object` in `v3` mode animates the sway. A 64×64 eight-frame sway is one generation.

Generate them in **neutral light**, not per time of day. The overlay in Task 6 lights them.

- [ ] **Step 4: Measure what Tasks 4 and 5 need**

For each foliage layer record its position in room units, its cell size, its frame count and its frame rate. For each backdrop record nothing — they are drawn at the room's own viewBox and need no constants.

Record every number in `scenes/README.md` beside the job id it came from, marking any derived value as derived rather than measured. Phase D's README is the model.

- [ ] **Step 5: Verify dimensions**

Run: `python3 -c "from PIL import Image; [print(f, Image.open(f).size) for f in __import__('glob').glob('resources/js/patyourself/scenes/*.png')]"`
Expected: every backdrop `(144, 114)`; every foliage sheet a whole multiple of its cell size.

- [ ] **Step 6: Commit**

```bash
git add resources/js/patyourself/scenes/
git commit -m "feat(blob): the forest, in four lights"
```

---

### Task 2: Four times of day, and the light that goes over them

**Files:**
- Modify: `config/companion.php` — the `room` block gains `sunrise` and every entry gains a light
- Modify: `resources/js/patyourself/companion.tsx` — `RoomPalette` gains two fields
- Test: `tests/Unit/Companion/CompanionRoomConfigTest.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: `RoomPalette` as `{ from: number; wall: string; window: string; light: string; dim: number }`, and a `room` config with four entries.

**`partOfDay()` needs no change.** It already sorts by `from` and returns the last entry that has started, wrapping past midnight. A fourth part of day is data.

`dusk` keeps its name. It is the sunset one and it was named before this phase; renaming it would touch the `data-part-of-day` attribute that existing tests read, for nothing.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Companion;

use PHPUnit\Framework\TestCase;

class CompanionRoomConfigTest extends TestCase
{
    /**
     * Read from the file rather than through `config()`: this class boots no
     * application, which is the same reason CompanionLadderTest reads its own
     * config directly.
     *
     * @return array<string, array{from: int, wall: string, window: string, light: string, dim: float|int}>
     */
    private function room(): array
    {
        return (require dirname(__DIR__, 3).'/config/companion.php')['room'];
    }

    public function test_the_day_has_four_parts(): void
    {
        $this->assertSame(
            ['sunrise', 'day', 'dusk', 'night'],
            array_keys($this->room()),
        );
    }

    public function test_each_part_starts_after_the_one_before_it(): void
    {
        $previous = -1;

        foreach ($this->room() as $name => $palette) {
            $this->assertGreaterThan(
                $previous,
                $palette['from'],
                "{$name} does not start after the part before it",
            );

            $previous = $palette['from'];
        }
    }

    /**
     * The overlay is drawn over everything including Blob, so a daytime
     * opacity above zero would tint the character at noon for no reason.
     */
    public function test_daylight_adds_no_tint(): void
    {
        $room = $this->room();

        $this->assertSame(0, $room['day']['dim']);
        $this->assertGreaterThan(0, $room['night']['dim']);
        $this->assertGreaterThan(0, $room['sunrise']['dim']);
        $this->assertGreaterThan(0, $room['dusk']['dim']);
    }

    public function test_every_part_carries_a_full_palette(): void
    {
        foreach ($this->room() as $name => $palette) {
            foreach (['from', 'wall', 'window', 'light', 'dim'] as $key) {
                $this->assertArrayHasKey($key, $palette, "{$name} is missing {$key}");
            }
        }
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=CompanionRoomConfigTest`
Expected: FAIL — the room has three parts and no `light`.

- [ ] **Step 3: Edit the config**

```php
'room' => [
    'sunrise' => ['from' => 5, 'wall' => '#F2E0D0', 'window' => '#F0B98A', 'light' => '#F4A15C', 'dim' => 0.18],
    'day' => ['from' => 8, 'wall' => '#EFE6D6', 'window' => '#B9D5E4', 'light' => '#FFFFFF', 'dim' => 0],
    'dusk' => ['from' => 18, 'wall' => '#E7D2BE', 'window' => '#E9A468', 'light' => '#E2762F', 'dim' => 0.22],
    'night' => ['from' => 21, 'wall' => '#2F3A40', 'window' => '#1A2530', 'light' => '#2B3F6B', 'dim' => 0.42],
],
```

Update the block's comment: it currently says three parts and explains the midnight wrap. Both still hold; the count does not.

- [ ] **Step 4: Widen the TypeScript type**

In `resources/js/patyourself/companion.tsx`:

```ts
export interface RoomPalette {
    /** The local hour this part of the day starts at. */
    from: number;
    wall: string;
    window: string;
    /** The colour the whole scene is washed with, Blob included. */
    light: string;
    /** How strongly. Zero at midday, when the light needs no help. */
    dim: number;
}
```

- [ ] **Step 5: Run the tests and Pint**

Run: `php artisan test --compact --filter=Companion && npx tsc --noEmit && vendor/bin/pint --dirty --format agent`
Expected: all pass; exactly one TypeScript error, at `catch-up.tsx:132`.

- [ ] **Step 6: Prove the guards go red**

- Remove `sunrise` → the four-parts test fails.
- Set `day.dim` to `0.2` → the daylight test fails.
- Give `night` a `from` of 4 → the ordering test fails.
- Drop `light` from `dusk` → the full-palette test fails.

- [ ] **Step 7: Commit**

```bash
git add config/companion.php resources/js/patyourself/companion.tsx tests/Unit/Companion/CompanionRoomConfigTest.php
git commit -m "feat(blob): a fourth part of the day, and a light to wash it with"
```

---

### Task 3: Which scene the record puts Blob in

**Files:**
- Modify: `config/companion.php` — a new `scenes` block
- Modify: `app/Services/Companion/CompanionState.php` — a `scene()` method, and `scene` in the payload
- Modify: `app/Services/Companion/CompanionResolver.php` — pass the scenes config through
- Test: `tests/Feature/Companion/CompanionSceneTest.php` (create)

**Interfaces:**
- Consumes: the unlock counts the resolver already computes.
- Produces: `scene` as a string in the companion payload, one of `forest` or `cabin`.

**The threshold rule.** `cabin` sits at `insights: 5`. It must be **below** an established record so nothing goes out of view, and it **never moves again** — E2 and E3 insert `lean-to` and `hut` below it. A threshold raised in a later phase would walk a record backwards from a cabin to a hut, and nothing in this feature has ever regressed.

- [ ] **Step 1: Write the failing tests**

```php
public function test_a_new_record_starts_outside(): void
{
    $user = User::factory()->create();

    $this->assertSame('forest', $this->resolve($user)->toArray()['scene']);
}

public function test_a_record_that_has_reached_the_threshold_is_indoors(): void
{
    $user = $this->userWithInsights(5);

    $this->assertSame('cabin', $this->resolve($user)->toArray()['scene']);
}

/**
 * The regression this threshold exists to prevent: an established record
 * must not lose sight of anything it earned.
 */
public function test_an_established_record_keeps_every_object_it_earned(): void
{
    $user = $this->userWithInsights(9);
    $state = $this->resolve($user)->toArray();

    $this->assertSame('cabin', $state['scene']);
    $this->assertContains('bookshelf', $state['room_objects']);
}

public function test_the_scene_is_read_from_the_record_and_never_stored(): void
{
    $user = $this->userWithInsights(5);

    $this->resolve($user);

    $this->assertDatabaseMissing('users', ['id' => $user->id, 'scene' => 'cabin']);
}
```

Write `resolve()` and `userWithInsights()` as private helpers following the conventions already in `CompanionResolverTest`.

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test --compact --filter=CompanionSceneTest`
Expected: FAIL — no `scene` key in the payload.

- [ ] **Step 3: Add the config block**

```php
/*
|--------------------------------------------------------------------------
| Scenes
|--------------------------------------------------------------------------
|
| Where Blob is. Ordered, walked like the ladder: the last entry whose
| threshold the record has passed wins, and the first entry is where a
| record with nothing behind it starts.
|
| A THRESHOLD HERE NEVER MOVES ONCE SET. Later phases add scenes BELOW the
| ones above them; raising one would walk an established record backwards
| out of a building it has already earned, and nothing in this feature has
| ever regressed.
|
*/

'scenes' => [
    ['name' => 'forest', 'trigger' => 'logs', 'at' => 0],
    ['name' => 'cabin', 'trigger' => 'insights', 'at' => 5],
],
```

- [ ] **Step 4: Derive it in `CompanionState`**

Add a `scene()` method that walks the scenes config against the same counts the ladder walk already has, returning the last satisfied entry's name and falling back to the first entry's name. Add `'scene' => $this->scene()` to `toArray()`.

Keep it a pure read — no property is stored and no row is written.

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact --filter=Companion && vendor/bin/pint --dirty --format agent`
Expected: all pass, Pint clean.

- [ ] **Step 6: Prove the guards go red**

- Return `'cabin'` unconditionally → the new-record test fails.
- Lower `cabin`'s threshold to `insights: 0` → the new-record test fails.
- Raise it to `insights: 50` → both established-record tests fail, which is the regression guard doing its job.

- [ ] **Step 7: Commit**

```bash
git add config/companion.php app/Services/Companion/ tests/Feature/Companion/CompanionSceneTest.php
git commit -m "feat(blob): the record decides which scene Blob is in"
```

---

### Task 4: The scene registry, and the forest backdrop

**Files:**
- Create: `resources/js/patyourself/scenes.ts`
- Create: `resources/js/patyourself/scenes.test.ts`
- Modify: `resources/js/patyourself/companion-room.tsx` — draw a scene rather than only the room
- Modify: `tests/Feature/Companion/CompanionVocabularyTest.php` — add `scenes.ts` and `scenes/README.md`

**Interfaces:**
- Consumes: the PNGs and measured constants from Task 1; `scene` from Task 3.
- Produces:
  - `interface FoliageSpec { sheet: string; cell: number; at: readonly [number, number]; animation: AnimationName }` — declared here because `SceneSpec` holds a list of them; Task 5 is what draws them
  - `interface SceneSpec { name: string; backdrops: Record<string, string>; base: string; foliage: readonly FoliageSpec[] }`
  - `SCENES: Record<string, SceneSpec>` — **`forest` is declared first**, because the fallback is the first entry
  - `sceneFor(name: string): SceneSpec` — falls back to the first scene rather than throwing

In Task 4 the cabin's `foliage` is an empty array and the forest's is populated but not yet drawn. Task 5 gives them a renderer.

**The cabin keeps its own drawing.** Today's wall, floor, window and `ROOM_OBJECTS` are the `cabin` scene's renderer, moved behind the registry unchanged. Do not redraw them and do not change what the room contains.

- [ ] **Step 1: Write the failing tests**

```ts
import { describe, expect, it } from 'vitest';

import { SCENES, sceneFor } from './scenes';

describe('scenes', () => {
    it('knows the two scenes E1 ships', () => {
        expect(Object.keys(SCENES).sort()).toEqual(['cabin', 'forest']);
    });

    it('gives every scene a backdrop for every part of the day', () => {
        for (const scene of Object.values(SCENES)) {
            for (const part of ['sunrise', 'day', 'dusk', 'night']) {
                expect(scene.backdrops[part] ?? scene.base).toBeTruthy();
            }
        }
    });

    /**
     * Naming a scene must never be able to break the screen — the same rule
     * item types, room objects and animations already follow.
     */
    it('falls back rather than throwing on a scene it does not know', () => {
        expect(sceneFor('swamp')).toBe(SCENES.forest);
        expect(sceneFor('')).toBe(SCENES.forest);
    });

    /**
     * A backdrop that fails to load leaves this behind it, so Blob never
     * stands on nothing.
     */
    it('gives every scene a flat base colour', () => {
        for (const scene of Object.values(SCENES)) {
            expect(scene.base).toMatch(/^#[0-9A-Fa-f]{6}$/);
        }
    });
});
```

And in `companion-room.test.tsx`:

```tsx
it('draws the forest backdrop for a record that is still outside', () => {
    const container = drawRoom({ scene: 'forest' });

    expect(container.querySelector('.scene-backdrop')).not.toBeNull();
    expect(container.querySelector('.room-object--bookshelf')).toBeNull();
});

it('draws the room, with everything in it, for a record that is indoors', () => {
    const container = drawRoom({
        scene: 'cabin',
        room_objects: ['bookshelf'],
    });

    expect(container.querySelector('.room-object--bookshelf')).not.toBeNull();
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `npx vitest run resources/js/patyourself/scenes.test.ts resources/js/patyourself/companion-room.test.tsx`
Expected: FAIL — cannot resolve `./scenes`.

- [ ] **Step 3: Write the registry**

Import the four backdrops as modules so Vite hashes them. `base` is the flat colour behind each backdrop. Take the foliage numbers from `scenes/README.md`.

- [ ] **Step 4: Make the compositor scene-aware**

`CompanionRoom` picks its scene with `sceneFor(companion.scene)`, draws the backdrop for the current part of day, then the scene's own contents — foliage for the forest, `ROOM_OBJECTS` for the cabin — then Blob. Everything the room draws today stays exactly as it is, one level down.

- [ ] **Step 5: Add the new files to the vocabulary scan and prove the list bites**

Add `resources/js/patyourself/scenes.ts` and `resources/js/patyourself/scenes/README.md` to `sourceFiles()`. Then put `cooldown` in a comment in `scenes.ts`, run `php artisan test --compact --filter=CompanionVocabularyTest`, and confirm it fails **naming that file** before removing it. A file absent from that list is scanned by nothing, and this project has been bitten by exactly that.

- [ ] **Step 6: Run everything**

Run: `npx vitest run && npx tsc --noEmit && php artisan test --compact --filter=Companion`
Expected: all pass; exactly one TypeScript error.

- [ ] **Step 7: Prove the guards go red**

- Return the requested scene without a fallback → the unknown-scene test fails.
- Draw `ROOM_OBJECTS` in the forest → the forest test fails.
- Remove a backdrop from one part of day → the coverage test fails.

- [ ] **Step 8: Commit**

```bash
git add resources/js/patyourself/scenes.ts resources/js/patyourself/scenes.test.ts resources/js/patyourself/companion-room.tsx resources/js/patyourself/companion-room.test.tsx tests/Feature/Companion/CompanionVocabularyTest.php
git commit -m "feat(blob): a scene registry, and a forest to put in it"
```

---

### Task 5: Trees that move

**Files:**
- Modify: `resources/js/patyourself/scenes.ts` — foliage entries
- Modify: `resources/js/patyourself/companion-room.tsx` — draw and animate foliage
- Modify: `resources/js/patyourself/companion-animations.ts` — foliage animation entries
- Test: `resources/js/patyourself/companion-room.test.tsx`

**Interfaces:**
- Consumes: foliage sheets and their measured constants from Task 1; `FoliageSpec` and the populated `forest.foliage` list, both declared in Task 4.
- Produces: the renderer for those entries, plus their animation entries in `ANIMATIONS`.

**One loop, not two.** Foliage subscribes to `use-sprite-clock`, which has run exactly one `requestAnimationFrame` for the whole app since Phase C so two Blobs cannot drift apart. Trees drift apart from each other on purpose — but that comes from different frame rates and different sheet phases, not from a second loop.

- [ ] **Step 1: Write the failing tests**

```tsx
it('draws every foliage layer the forest declares', () => {
    const container = drawRoom({ scene: 'forest' });

    expect(container.querySelectorAll('.scene-foliage').length).toBe(
        SCENES.forest.foliage.length,
    );
});

it('advances foliage with the frame, like everything else', () => {
    const first = drawRoom({ scene: 'forest', frame: 0 })
        .querySelector('.scene-foliage')!
        .getAttribute('viewBox');
    const second = drawRoom({ scene: 'forest', frame: 1 })
        .querySelector('.scene-foliage')!
        .getAttribute('viewBox');

    expect(first).not.toBe(second);
});

/**
 * A still tree is a tree. A missing one is a hole.
 */
it('draws a foliage layer with no animation as a static frame', () => {
    const container = drawRoom({ scene: 'forest', frame: 99 });

    expect(container.querySelectorAll('.scene-foliage').length).toBe(
        SCENES.forest.foliage.length,
    );
});

it('applies no transition to anything in the scene', () => {
    const container = drawRoom({ scene: 'forest' });

    for (const node of container.querySelectorAll('*')) {
        expect((node as HTMLElement).getAttribute('style') ?? '').not.toContain(
            'transition',
        );
    }
});
```

And in `use-sprite-clock.test.ts`, confirm the existing "exactly one loop" test still holds with foliage subscribers on screen.

- [ ] **Step 2: Run them to verify they fail**

Run: `npx vitest run resources/js/patyourself/companion-room.test.tsx`
Expected: FAIL — no `.scene-foliage` in the output.

- [ ] **Step 3: Implement**

Each foliage layer is a nested `<svg>` cropping its cell from its sheet, exactly as `SpriteBlobRenderer` crops Blob's, positioned at its measured `at` in room units. No transitions.

- [ ] **Step 4: Run everything and prove the guards go red**

- Hard-code the foliage frame to 0 → the advance test fails.
- Add a `transitionDuration` to a foliage layer → the no-transition test fails.
- Draw one fewer layer than the registry declares → the count test fails.

- [ ] **Step 5: Commit**

```bash
git add resources/js/patyourself/
git commit -m "feat(blob): wind in the trees, on the clock that was already running"
```

---

### Task 6: The light over everything

**Files:**
- Modify: `resources/js/patyourself/companion-room.tsx` — the overlay
- Modify: `resources/js/patyourself/blob-renderer.tsx` — correct the fixed-colour docblock
- Test: `resources/js/patyourself/companion-room.test.tsx`

**Interfaces:**
- Consumes: `light` and `dim` from `RoomPalette` (Task 2).
- Produces: one `<rect class="scene-light">` drawn last, over the backdrop, the foliage, the room objects **and Blob**.

**This reverses a documented ruling.** `blob-renderer.tsx` says Blob's colour is fixed because "a Blob that changes colour with its surroundings is a different Blob." That rule was written about light mode versus dark mode, where the surroundings are application chrome. Standing outdoors at dusk is a different question, and a creature lit differently from the world around it reads as pasted on. **Correct that docblock in the same commit** — do not leave it contradicting the code.

- [ ] **Step 1: Write the failing tests**

```tsx
it('washes the whole scene, Blob included, after dark', () => {
    const container = drawRoom({ scene: 'forest', hour: 23 });
    const light = container.querySelector('.scene-light') as SVGRectElement;
    const blob = container.querySelector('.blob-anim') as SVGGElement;

    expect(light).not.toBeNull();
    // Drawn last, so it covers Blob rather than sitting behind it.
    expect(
        blob.compareDocumentPosition(light) &
            Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
});

/**
 * Midday needs no help. An overlay at noon would tint Blob for nothing.
 */
it('draws no light at midday', () => {
    const container = drawRoom({ scene: 'forest', hour: 12 });

    expect(container.querySelector('.scene-light')).toBeNull();
});

it('uses a different light at each part of the day', () => {
    const fillAt = (hour: number) =>
        drawRoom({ scene: 'forest', hour })
            .querySelector('.scene-light')
            ?.getAttribute('fill');

    expect(new Set([fillAt(6), fillAt(19), fillAt(23)]).size).toBe(3);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `npx vitest run resources/js/patyourself/companion-room.test.tsx`
Expected: FAIL — no `.scene-light`.

- [ ] **Step 3: Implement**

Draw the overlay last, covering the room's full viewBox, with `fill={palette.light}`, `opacity={palette.dim}` and `style={{ mixBlendMode: 'multiply' }}`. Skip it entirely when `dim` is zero — an `opacity={0}` rect is a node that does nothing, and the test above pins its absence.

- [ ] **Step 4: Run everything and prove the guards go red**

- Draw the overlay before Blob → the covers-Blob test fails.
- Render it when `dim` is zero → the midday test fails.
- Use one colour for every part → the different-light test fails.

- [ ] **Step 5: Commit**

```bash
git add resources/js/patyourself/
git commit -m "feat(blob): one light over the scene and the creature in it"
```

---

### Task 7: Look at it

**Files:**
- Create: `storage/app/forest-preview.html` (throwaway, not committed)

**Interfaces:**
- Consumes: everything above.
- Produces: a human judgement. Phase D shipped four defects past a green suite and every one was found by looking.

- [ ] **Step 1: Build**

Run: `npm run build`
Skipping this makes `PwaManifestTest` skip and the assertion count drop.

- [ ] **Step 2: Render both scenes, four times of day, at both shipping sizes**

Composite what the components actually emit — not a reimplementation of their maths. Phase D's mistake was checking geometry that duplicated the renderer's own arithmetic; dump the real DOM and rasterise that.

- [ ] **Step 3: Serve and look**

Run: `php -S 127.0.0.1:8899 -t storage/app`
Herd serves the **main checkout**, never a worktree.

Judge, in this order:
1. **Does the forest's ground angle match Blob's?** This is the whole reason the phase exists. If the ground reads flat while Blob is seen from above, the art is wrong and no code change fixes it.
2. **Do Blob and the scene share one pixel density?** They must, at 144×114 against a 64px cell.
3. **Does the light read at sunrise, dusk and night — and does Blob sit inside it** rather than glowing against it?
4. **Do the trees move like wind** rather than like a metronome?
5. **Does the cabin look exactly as it did before**, apart from the new light and the fourth part of day?

- [ ] **Step 4: Check the app**

Open `/companion` with `COMPANION_SCENE=forest` and without it. Under browser automation `data-frame` reads 0 forever — the clock stops on `document.hidden` — so trust `data-animation` and `data-part-of-day`.

- [ ] **Step 5: Full suite**

Run: `php artisan test --compact && npx vitest run && npx tsc --noEmit && vendor/bin/pint --dirty --format agent`
Expected: everything passes; exactly one TypeScript error, at `catch-up.tsx:132`.

---

## Notes for the executor

- **Do not resize the backdrops.** 144×114 is Blob's own pixel grid; any other size puts the character and the world on different grids.
- **Do not change what the room contains.** The cabin is today's room moved behind a registry, nothing more.
- **Do not lower or raise the cabin's threshold.** Later phases insert scenes below it. Moving it walks an established record backwards.
- **Do not start a second animation loop.** Foliage subscribes to the one that already exists.
- If a backdrop's angle is wrong, the fix is regenerating it, not compensating in the layout. A layout that apologises for bad art gets worse with every scene.
