# Blob — a life on the clock

Blob stands there. It breathes, it blinks, it walks once it has learned to, and it does exactly the
same thing at four in the morning as it does at noon. This document adds a day: Blob sleeps at
night, stretches in the morning, and looks around during the day.

Everything here is a function of the clock and of `config('companion.room')`. Nothing here reads
`logCount`, `insightCount`, an outcome, a date, or a gap between dates. That is the whole design
constraint, and every decision below is downstream of it.

---

## The frame

**"Blob's life is never a mirror."** A sleeping Blob is safe when it sleeps because it is night. It
becomes a rebuke the instant it correlates with the person — and the failure mode is quiet: open the
app at 11pm to log the day, find a sleeping creature, read it as *you're late*.

The rule is therefore stronger than "do not mention the person". It is a restriction on the
**inputs**: the two functions that choose what Blob is doing may read the hour and the room's
palette, and nothing else. A test asserts that directly (see Testing), because this is the one
property a later well-meaning change would break without noticing.

Three properties of the existing machinery do most of the work, and all three were verified rather
than assumed:

- `ambientFor(companion)` returns **exactly one** animation, and `walk`/`idle` share the `ambient`
  channel precisely so that only one can run. Sleep joins that channel and inherits the guarantee.
- `use-sprite-clock.ts` already lets a **reaction** cover the ambient and hand it back. `pet`,
  `play` and `notice` therefore beat sleep for free. Log an outcome at 11pm and Blob stirs exactly
  as it does at noon — which is the real answer to the *you're late* worry. The creature still
  responds; it simply started from a different pose.
- `partOfDay(hour, room)` works, wraps past midnight, and takes an overridable `hour`. It needs to
  move house, but not to change.

## Decisions

### Sleep is a behaviour, not an ability

No ladder rung, no threshold, no announcement. Every Blob sleeps from the first outcome logged.

`selfStartedFor`'s docblock already states the principle for the one existing exemption: *"blink
always: it is not an ability, it is being alive."* Sleeping is on that side of the line, and two
consequences of the alternative settle it:

- **A rung has to say something.** Copy for it would read "Blob can sleep now", which implies it
  could not before — false — and announces rest as an achievement, which is the mirror rule failing
  in the message layer rather than in the drawing.
- **The ladder is walked in order and stops at the first unsatisfied entry.** Inserting a sleep rung
  mid-ladder delays every later rung for established records. Appending one means a Blob three
  outcomes deep stands awake all night while a deeper record sleeps: the same creature behaving
  differently according to how much is in the record. That is the mirror, arrived at from the side
  nobody was watching. And §9 of `docs/BLOB.md` freezes thresholds on merge, so the wrong choice
  here is only fixable later by moving a rung.

The rejected alternative is a rung at, say, `logs: 2`, on the argument that the ladder is how this
feature announces anything new. It is rejected because *nothing about sleep is new to Blob* — the
app is only now drawing what was always true.

**The general rule this establishes, for the next behaviour:** a behaviour is ladder-exempt if and
only if no rung would ever announce it *and* it says nothing about capability. `blink` and `sleep`
pass. `walk`, `wave`, `jump`, `read` and `carry` all fail, correctly.

Two things fall out. `sleep` never appears in `abilities`, so `selfStartedFor`'s earned-ability
filter is untouched by it. And `sleep` is **never** in `actionsFor`: a Sleep button at two in the
afternoon is the user putting the creature down, and Blob never asks to be pressed.

### Ambient selection becomes a precedence list, inside `ambientFor`

```ts
export function ambientFor(
    companion: CompanionData,
    hour: number = new Date().getHours(),
): AnimationName {
    if (asleepAt(hour, companion.room)) {
        return 'sleep';
    }

    return companion.abilities.includes('walk') ? 'walk' : 'idle';
}
```

Two rungs, one return value. Sleep comes first because it is a state of the whole creature, where
walking and idling are what an awake Blob does; the ordering is a claim about the creature, not a
priority number.

The default argument reads the clock, so every existing call site — `pages/companion.tsx` and the
`Companion` component itself — is unchanged, and tests pass an hour explicitly. That is the same
seam `CompanionRoom` already uses for `hour`.

Two alternatives were considered and rejected:

- **A second "override" concept**, or a fourth channel for animations that pre-empt the ambient. A
  channel says *who owns* an animation — the creature or the world it stands in — not who wins. This
  would be a two-rung precedence list wearing more vocabulary, and it would leave two places (the
  channel and `ambientFor`) able to disagree about what Blob is doing.
- **Deriving it server-side** and shipping `part_of_day` in the payload. That is Q4's question
  answered wrongly; see below.

### Which part of the day is the sleeping one is config's to say

`config/companion.php` gains one key on one part:

```php
'night' => ['from' => 21, ..., 'dim' => 0.42, 'asleep' => true],
```

`RoomPalette` gains `asleep?: boolean`. `CompanionState`'s array-shape docblock gains it too. No
other PHP changes: `CompanionState::toArray()` passes `$this->room` through whole, and `$this->room`
is `config('companion.room')` verbatim, so the flag reaches the client the moment it is authored.

Two alternatives, both rejected:

- **Hardcode the part name `'night'` in the TypeScript.** Smallest diff, and it couples the drawing
  to a config key name: rename the part and sleep silently stops. The parts are data everywhere else
  in this feature — `partOfDay` does not know their names either, it sorts them by `from`.
- **Reuse `isDark(palette.wall)`,** the predicate the lamp keys off. Tempting, and it has the
  attraction that a lit lamp and a sleeping Blob could never disagree. Rejected because the lamp is
  *inferring* a fact about a colour ("this wall reads dark, so a lamp would be lit"), where the part
  of the day Blob sleeps through is an **authored decision** and belongs written down beside the
  hours that define it. It also reads oddly outdoors, where there is no wall.

`asleep` is authored by us in config, alongside the ladder. It is not a setting: nothing in the app
exposes it, and the reader cannot choose it.

### Nothing self-starts while Blob is asleep

This is the subtle one. `blink` has a row on **every** form and fires on a random interval for every
Blob that exists. Left alone, the auto-timer opens a sleeping Blob's eyes for 125ms every few
seconds — a creature asleep with a twitching eyelid, and a bug no class-name assertion would ever
catch.

So `selfStartedFor` becomes part-aware and returns an empty list during the asleep part. The earned
one-shots (`wave`, `jump`) stop too, for the same reason.

```ts
export function selfStartedFor(
    companion: CompanionData,
    hour: number = new Date().getHours(),
): AnimationName[] {
    if (asleepAt(hour, companion.room)) {
        return [];
    }

    const earned = /* unchanged: ambient + autoEvery + in abilities */;

    return wakingAt(hour, companion.room)
        ? ['blink', 'look', 'stretch', ...earned]
        : ['blink', 'look', ...earned];
}
```

The hook needs no change: it already schedules only what this list names, and it already keys its
timer effect off the list's *content* rather than its identity.

### Waking is a morning-weighted one-shot, not a transition

`stretch` is a self-starting one-shot on the ambient channel, eligible **only during the part that
follows the asleep part.** It fires on the existing random interval, a few times through the
morning, for whoever is actually there — and does not exist at any other hour.

Two facts kill the boundary event outright, and they are worth stating because they are what makes
this the cheap answer rather than the lazy one:

- **Nothing ticks the hour.** The part of day is read at render. A page open across 05:00 keeps the
  old part until something re-renders — which is already true of the room's light and has been since
  it shipped.
- **The clock stops on `document.hidden`.** A tab left open overnight is not running at 5am anyway.

A transition animation therefore needs a new timer to exist at all, and would then play to an empty
room at dawn. A sprite row is a row on up to three sheets plus its anchor offsets; it has to be seen
to be worth that.

The rejected alternatives: **fire on the boundary**, which needs an hour ticker (a `setInterval`,
which breaks no rule — the ban is on a second `requestAnimationFrame` loop — but buys a transition
almost nobody sees); and **first load after sunrise**, which needs to remember whether this is the
first visit today, and a memory of the person's visits is the first step toward the drawing
commenting on them.

Falling asleep gets the same answer for the same reasons: the first render after 21:00 is already
asleep. There is no going-to-bed animation.

**Which part is "waking" is derived, not named.** `wakingAt` sorts the parts by `from` and finds the
first part after an asleep one that is not itself asleep — `sunrise`, today, without the word
`'sunrise'` appearing anywhere in the TypeScript.

> **Correction, added after implementation.** "Nothing ticks the hour" above turned out to be false:
> `useSpriteClock`'s rAF tick calls `setFrame` from inside `Companion`/`CompanionRoom`, which re-renders
> whatever reads `ambientFor`, `selfStartedFor` and `partOfDay` with a fresh `new Date().getHours()` on
> every tick — effectively ticking the hour at the running ambient's own frame rate (about 1Hz asleep,
> 2Hz idle) whenever the tab is visible. See `docs/BLOB.md` §6 for the full mechanism. The decision
> stands anyway: what was missing was never detection of the boundary, only an animated *transition*
> across it, and that still has to earn a sprite row on its own terms. The one place the original premise
> does hold is `prefers-reduced-motion`, where the hook never subscribes and the hour really is read only
> at render.

### Pottering is `look`, in place

`look` is a self-starting one-shot for every awake part: Blob turns to look at something, and turns
back. It joins `blink` as ladder-exempt, by the rule established above — nothing announces it and it
claims no capability.

**It does not go anywhere.** Pottering *about* — crossing the room, wandering — is out, and not for
taste: see the next decision.

### The browser's clock, for the same reason the light already uses it

`config/companion.php` ruled on this out loud when the room shipped: the parts of day are read from
the client because "the room should look different at breakfast and at dinner, and which of those it
is depends on where the person is sitting, not on where the server is."

Sleep **must** share the light's clock. A sleeping Blob in a midday room is a bug you can see, and
two clocks is exactly how you get one. Since the light's clock is browser-local and settled, sleep
is browser-local.

The app does store a per-user `timezone` and uses it server-side (`ActionController`,
`CatchUpController`, `NotebookController`, `IntentionController`). That is the right clock for
**scheduling**, which has to be stable no matter which device is in your hand. The drawing is for
the eyes in the room, and a traveller looking at their phone at 2am local really is looking at it at
2am.

The cost, written down:

- Near midnight a traveller's Blob-night and their "today" (occasions, the catch-up list) can
  disagree by hours. No screen renders both facts in one frame, so it is an inconsistency nobody
  sees at once.
- A device with a wrong timezone puts Blob to sleep at the wrong hour, and nothing server-side can
  correct it. Accepted: that device already shows the wrong room light today.

### The sleeping pose stays upright

The expensive constraint, and the one an implementer would guess wrong on. **The sleeping Blob must
not lie down, curl up, or rotate.**

Two settled invariants forbid it:

- **Every per-frame anchor delta has `x = 0`.** That single fact is why a worn item is one sprite
  rather than one per frame (`docs/BLOB.md` §6). A pose that translates the body sideways needs
  per-frame horizontal anchors, and the hat, glasses, scarf and shoes all stop following the body
  until it gets them.
- **`sprites/README.md` step 3: "Reject any frame where the body rotates."** The anchor table has no
  concept of a tilted head, so a hat cannot tilt with one.

The art brief is therefore: **upright slump, eyes closed, slow deep breath, same registration,
vertical movement only.** No sideways drift, no rotation, no sleep symbol floating overhead — a
symbol is the app doing the saying.

The same constraint applies to `look` and `stretch`, and it is why pottering is in place. `walk`
itself has always been an in-place cycle for this reason.

## Architecture

```
config/companion.php              'asleep' => true on the night part          (1 line)
CompanionState.php                array-shape docblock gains asleep?: bool    (docblock only)

resources/js/patyourself/
  part-of-day.ts        NEW   RoomPalette, partOfDay, asleepAt, wakingAt
  companion.tsx               ambientFor + selfStartedFor gain an hour
  companion-animations.ts     sleep, stretch, look
  blob-renderer.tsx           three cases in the SVG renderer; eyesClosed for sleep
  sprite-layout.ts            sleep row on all three forms; stretch + look on arms;
                              per-frame offsets for each
  sprites/humus-blob.png      +1 row, repadded
  sprites/humus-legs.png      +1 row, repadded
  sprites/humus-arms.png      +3 rows
  sprites/README.md           rows, frame choices, every measurement
```

**`partOfDay` has to move, and the reason is a cycle.** It lives in `companion-room.tsx`, which
imports `arrivingItem`, `describe`, `CompanionData` and `RoomPalette` from `companion.tsx`. Having
`companion.tsx` import `partOfDay` back closes the loop. So `part-of-day.ts` takes `partOfDay` and
**owns `RoomPalette`**; `companion.tsx` and `companion-room.tsx` both import from it. One direction,
no cycle, and no type-only import papering over a real dependency.

`part-of-day.ts` is a new companion source file and **goes on
`CompanionVocabularyTest::sourceFiles()`.** A file absent from that list is scanned by nothing, and
this project has been bitten by exactly that.

### The registry entries

Data only, as always — the registry learns three names and no new field:

| Name | Channel | Loop | Frames | fps | `autoEvery` |
| --- | --- | --- | --- | --- | --- |
| `sleep` | ambient | yes | 4 | 1 | none — it is a state, not a one-shot |
| `stretch` | ambient | no | 6 | 8 | `[20000, 60000]` |
| `look` | ambient | no | 4 | 8 | `[12000, 35000]` |

`sleep` at 1fps is the slowest rate in the registry, and that is the point: a four-second breath is
what separates sleeping from idling, more than the pose does.

Those are the numbers to build against. Rates and frame *selections* may still change after looking
at the art — `idle` and `blink` were both chosen out of four-frame generations rather than generated
at two — and any change is recorded in `sprites/README.md`.

**No new animation may exceed 6 frames.** `columnsOf` is the widest animation a form carries, and
`play`'s 6 is the current maximum on `arms`; a 7th frame widens that sheet and every row in it needs
repadding. Six is not a hard rule of the system, only the cheap boundary — crossing it is a
deliberate cost, not an accident.

**No part-of-day field is added to the registry.** Eligibility is `selfStartedFor`'s job, which is
already the one place that decides what a given Blob may self-start. A `during: ['sunrise']` field
would put part names back in the registry, which is the coupling the `asleep` flag exists to avoid.

### `part-of-day.ts`

```ts
export function partOfDay(hour: number, room: Record<string, RoomPalette>): string
export function asleepAt(hour: number, room: Record<string, RoomPalette>): boolean
export function wakingAt(hour: number, room: Record<string, RoomPalette>): boolean
```

`partOfDay` moves unchanged. The two new predicates are thin: `asleepAt` is the current part's
`asleep === true`; `wakingAt` sorts by `from` and asks whether the current part is the first
non-asleep part following an asleep one, wrapping past midnight exactly as `partOfDay` does.

### The art

Pixel Lab, `animate_character` with `mode: "v3"`, `directions: ["south"]`,
`keep_first_frame: false`, on the three existing character states recorded in `sprites/README.md`
(`blob` 60d6031a…, `legs` 7cb62b7a…, `arms` 5c2e3e54…). Brief every animation with the body upright
and level, and reject any frame that rotates or drifts sideways — that is a re-roll, never something
to fix in the anchor table.

Rows are **appended**, never inserted: a form's `animations` array is its row order, so inserting
would shift every row below it.

| Sheet | Today | After | Rows added |
| --- | --- | --- | --- |
| `humus-arms.png` | 384×512 (6×8) | 384×704 (6×11) | `sleep`, `stretch`, `look` |
| `humus-blob.png` | 128×128 (2×2) | 256×192 (4×3) | `sleep` |
| `humus-legs.png` | 128×128 (2×2) | 256×192 (4×3) | `sleep` |

The sprite renderer sizes its `<image>` as `columnsOf(form) * CELL` by `animations.length * CELL`,
so **a sheet's pixel dimensions must equal that product exactly** or the whole sheet scales. Adding
a 4-frame `sleep` to `blob` and `legs` takes their column count from 2 to 4, so those two sheets are
repadded with transparency on the right — existing pixels keep their exact position, which the
"cells keep the exact position Pixel Lab returned" rule requires.

`sleep` gets a row on all three forms because it is the animation whose fallback is least
acceptable: the fallback-to-first-idle-frame contract holds, but it would mean a Blob one to four
outcomes deep standing awake for eight hours every night. `stretch` and `look` are arms-only —
`sprite-layout.test.ts` requires a row on the *fullest* form for every non-scene animation, and the
earlier forms fall back.

### Anchors

Each new row needs per-frame `head`, `face`, `neck`, `feet` and `hand` deltas, measured off the art
by the method in `sprites/README.md`, not guessed.

Two of the existing conventions do real work here:

- **`face` cannot be measured while the eyes are closed** — it is the row with the most white
  eye-highlight pixels, and a sleeping Blob has none. So `face` takes `head`'s own delta for those
  frames, exactly as it does for `blink`'s closed frame and for `pet`. A derivation, not a second
  guess.
- **`neck` is `face + 9`**, so its delta is `face`'s. **`hand` is
  `derivedHandDelta(head, feet)`**, and a guard already holds every row of the table to that.

An anchor whose delta is zero on every frame gets no row.

## Error handling

Every one of these is a config or payload shape the client must survive, because the room arrives
from the server and the client does not validate it:

| Case | Behaviour |
| --- | --- |
| No part carries `asleep` | Never asleep, never waking. Blob behaves exactly as it does today, plus `look`. This is the graceful degradation, and it is what makes the flag falsifiable. |
| Several parts carry `asleep` | Each is a sleeping part. The waking part is the first non-asleep part after any of them. |
| Every part carries `asleep` | Asleep always, waking never. `stretch` never fires. Config's fault, and it neither crashes nor throws. |
| `room` is empty | `partOfDay` returns `'day'` (existing behaviour); both new predicates return `false`. |
| `asleep` present but not `true` | Falsy is awake. The check is `=== true`, not truthiness. |
| A form has no row for `sleep` | Holds the first idle frame — the existing contract. Applies to nothing after this batch, and is why `sleep` is drawn on all three forms. |
| `prefers-reduced-motion` at night | Ambient is `sleep`, frame held at 0. Correct without new code: sleep is a state, and a held sleeping pose is the rest state. Nothing self-starts under reduced motion either, so `stretch` and `look` stay silent. |
| An outcome logged at 11pm | `notice` covers `sleep`, plays once, hands back. Existing reaction machinery, no new code, and the reason the 11pm visit is not a rebuke. |

## Testing

Every test below names the mutation that turns it red. A test whose mutation cannot be named is
decoration, and five of those have shipped on this project already.

**The mirror guard — the most important test in this batch.**

- `ambientFor` and `selfStartedFor` return the same thing at a fixed hour for `log_count: 1` and for
  `log_count: 900`, `insight_count: 50`, a full `unlocks` list and a `latest_unlock`.
  *Mutation: any read of the record in either function.*

**Ambient selection** (`companion.test.tsx`)

- Asleep hour → `sleep`; awake hour → `idle`, or `walk` when earned.
  *Mutation: dropping the asleep branch.*
- Asleep hour with `abilities: []` → still `sleep`.
  *Mutation: gating sleep on an earned ability.*
- `walk` earned and asleep → `sleep`, not `walk`.
  *Mutation: the two rungs in the wrong order.*

**The parts of day** (`part-of-day.test.ts`)

- `asleepAt` is false for a room whose parts carry no `asleep` key at all.
  *Mutation: defaulting the flag to true, or keying off the name `'night'`.* The shared fixture's
  night entry **does** carry `asleep: true` (it mirrors config), and this test builds its own room
  without it — so neither direction is a fixture default.
- `wakingAt` is true only in the part that follows the asleep part, in a room whose parts are
  renamed to nonsense with the hours kept.
  *Mutation: hardcoding `'sunrise'` or `'night'`.*
- Every row of the Error handling table above.
  *Mutations: as tabulated.*

**Self-started animations** (`companion.test.tsx`)

- Asleep → `[]`. Asserted as `not.toContain('blink')` explicitly, not just as a length.
  *Mutation: keeping the blink exemption unconditional — the eyelid-twitch bug.*
- Waking part → contains `stretch`; every other awake part → does not.
  *Mutation: making `stretch` eligible all day.*
- Every awake part → contains `look` and `blink`, with `abilities: []`.
  *Mutation: gating either behind the ladder.*
- Earned one-shots still appear when awake and are absent when asleep.
  *Mutation: the asleep branch returning `earned` instead of `[]`.*

**Reactions still win** (`companion.test.tsx`, driving the stubbed `requestAnimationFrame` the way
the existing "reacts again when a second outcome is logged" test does)

- At a night hour, `react('pet')` → `data-animation="pet"`, and after the reaction's duration →
  back to `sleep`, not `idle`.
  *Mutation: sleep pre-empting the reaction channel; or the hand-back reading a stale ambient.*
- Reduced motion at a night hour → `data-animation="sleep"`, `data-frame="0"`.
  *Mutation: the held pose falling back to idle.*

**Pinning the clock in tests.** Unit tests pass `hour` explicitly. Component and page tests cannot:
`pages/companion.tsx` calls `ambientFor(companion)` with the default argument, and `CompanionRoom`
reads `new Date().getHours()` for its own `hour` default, so two independent reads have to agree.
Those tests use `vi.setSystemTime`, which pins both. Threading an `hour` prop through the page is
rejected — the page's props come from the server, and a prop that exists only for tests is a lie
about the payload.

**The button row**

- `actionsFor` never offers `sleep`, `stretch` or `look`, at any hour.
  *Mutation: adding any of them to `ACTIONS`.*

**Sprite layout** (`sprite-layout.test.ts`)

- The existing "row for every animation the clock can play" guard covers the three new names on
  `arms` for free, and is red until the art lands. By design.
- `blob` and `legs` each carry a `sleep` row.
  *Mutation: shipping the arms row only and leaving early forms to the idle fallback.*
- **New guard: every per-frame delta in every form's table has `x === 0`.** This is the invariant
  that forbids a lying-down pose, it is currently only prose in `docs/BLOB.md` §6, and it is exactly
  what this batch could break.
  *Mutation: any table entry with a nonzero first component.*
- The existing `columnsOf` / sheet-size and `derivedHandDelta` guards cover the repadded sheets and
  the new hand rows unchanged.

**PHP**

- `CompanionRoomConfigTest`: exactly one part carries `asleep`, and it is the part with the latest
  `from`.
  *Mutation: flagging `day`, or flagging none.*
- `CompanionScreenTest` (or the resolver test): the Inertia payload's `room.night.asleep` is `true`.
  *Mutation: a shape filter dropping unknown keys on the way out.* Worth its own assertion despite
  `toArray()` passing the array through whole — a payload silently dropping a newly added key has
  bitten this project three times in one branch.
- `CompanionVocabularyTest`: `part-of-day.ts` is on `sourceFiles()`.
  *Mutation: adding the file and forgetting the list — then a banned word in it is scanned by
  nothing.*

**Render it and look.** Class-name assertions and geometry maths cannot judge a visual: the shoes
once shipped into the bottom-left corner with the feet bare and 340 tests green. Blob's frames also
freeze under browser automation, because the clock stops on `document.hidden` — so `data-frame`
reads 0 forever and a sleep cycle cannot be eyeballed that way.

The check is therefore a static harness: render `BlobRenderer` at fixed `(animation, frame)` pairs
for every frame of every new row, on every form that carries it, and lay them out side by side —
**composited from what the component emits**, never from a reimplementation of its arithmetic. Herd
serves the main checkout and never a worktree, so dump to HTML and serve it with
`php -S 127.0.0.1:8899 -t .`. What to look for: worn items still on the body in every frame, the
sprout not clipped, no sideways drift, and eyes actually closed throughout `sleep`.

**The five verification commands, all of them:**

```
npm run build && php artisan test --compact && npx vitest run && npx tsc --noEmit
  && vendor/bin/pint --dirty --format agent && npm run lint
```

Baseline measured on this branch before starting: **1000 PHP tests / 6319 assertions, 442 JS tests,
0 TypeScript errors.** `tsc` is a floor; any error is a regression.

## Out of scope

- **A going-to-bed animation.** See the waking decision — same reasoning, and the first render after
  21:00 is simply already asleep.
- **An hour ticker.** The part of day stays read-at-render, matching the room's light. If a live
  boundary is ever wanted it is one `setInterval` and a re-render, and it is a separate decision with
  its own cost. *(Correction: it turned out unnecessary regardless of that decision — the sprite clock's
  own rAF loop already re-reads the hour at its frame rate whenever the tab is visible, so the boundary
  is already live except under `prefers-reduced-motion`. See `docs/BLOB.md` §6.)*
- **Server-derived time of day.** Ruled on above; revisiting it means moving the room's light too,
  and the two must never split.
- **Sleep on the `svg` renderer's item layers.** The SVG renderer gets its three cases so the
  fixture-default path is honest, but its accessories were never anchor-per-frame and are not being
  reworked here.
- **`stretch` and `look` on the `blob` and `legs` forms.** Arms-only, falling back to idle. Revisit
  after looking at the art.
- **Weather, seasons, moon phases, any external API.** The room's own docblock: "this is the cheapest
  liveness in the whole feature and it stays that way."
- **Anything that reads the record.** Permanently, not just this batch.

## Assumptions

- Pixel Lab's `animate_character` can produce an upright, eyes-closed, breathing pose without
  rotating the body. If it cannot after re-rolls, the fallback is to select frames from a generated
  row the way `idle` and `blink` already were — and if *that* fails, `sleep` ships as a two-frame
  slow breath composed from existing closed-eye frames. The design does not depend on new art being
  perfect; it depends on the pose not lying down.
- Four parts of day, one of them asleep, is the shape config will keep. The predicates handle the
  other shapes (see Error handling) but the art is briefed for one night.
- Nobody is watching a single page across an hour boundary often enough for the read-at-render part
  of day to matter. This has been true of the room's light since it shipped and no one has reported
  it.
