# Blob — Phase D Design

Blob is the app's only reward surface. It tracks the work, not the user: a `failed` outcome advances
it exactly as far as a `completed` one, because the behaviour being rewarded is honest logging.
Nothing decays, nothing expires, and nothing anywhere names what is not yet unlocked — showing that
would turn Blob into a checklist, which is the one thing it must not be.

This phase closes the gap between what Blob claims about itself and what Blob is, gives the ladder
somewhere to go after its twelfth rung, and gives Blob a voice that comes from the coach.

Three sub-phases, each independently shippable:

- **D1 — the visible Blob.** Abilities you can see, an endless variant tail, a room that keeps filling.
- **D2 — Blob notices and speaks.** A reaction at the moment of logging, and coach-written remarks.
- **D3 — sprites.** Deferred, and gated on art rather than on engineering.

---

# D1 — The visible Blob

## Decisions

### Abilities become visible

`blob-renderer.tsx` declares `const ABILITIES: Record<string, AbilitySpec> = {}` — empty. Its own
docblock records why: "an ability with no entry here needs nothing drawn beyond the body, which is
why the ladder can name `wave` long before anyone poses it." That was a deliberate deferral, and it
is now the largest gap in the feature. Five abilities are announced in the app's own voice — "Blob
can walk", "Blob can read", "Blob can wave", "Blob can jump", "Blob can carry something" — and not
one of them changes anything you can see.

Abilities split into two kinds, and the existing architecture already has a seam for each:

- **Props** — `read` and `carry` need something drawn that the body does not have. They fill
  `ABILITIES` with an `extra` renderer, which `BlobRenderer` already maps over.
- **Poses** — `wave` and `jump` need frames. They join `ANIMATIONS`.

`walk` already has frames and needs neither.

**The payoff is `autoEvery`, which is already wired.** `use-sprite-clock.ts` schedules every ambient
animation carrying an `autoEvery` window to fire itself at a random interval — that is how Blob
blinks. Giving `wave` and `jump` the same treatment means an unlocked ability *surfaces on its own*,
so Blob visibly acquires behaviours over time instead of acquiring claims about behaviours.

One change the hook needs: it currently schedules every ambient animation that declares `autoEvery`.
It must schedule only the ones this Blob has actually unlocked, or a Blob that cannot wave will wave.

`wave` and `jump` follow the `blink` pattern — ambient channel, `loop: false`, with an `autoEvery`
window. They are things Blob does by itself, so they belong on the ambient channel; the `reaction`
channel stays for things that arrive from outside Blob, which today is `pet` and `play` and in D2
becomes an outcome being recorded.

### The ladder becomes endless, by recolouring

The last authored rung is `insights: 9`. After it Blob is finished, permanently, in an app meant to
run for years. That is the current behaviour by accident, and the worst of the available options.

Past the last authored rung the resolver **computes** rungs at a fixed insight cadence, each
recolouring an item type Blob already owns. This is what the four-type cap always anticipated — the
config already says that past the fourth type, "an item stage re-uses a type it already has and
names a `variant` instead — a recolour, not a new thing to collect."

The tail is data, like the ladder: a `tail` block naming the cadence, an ordered variant palette,
and a pool of authored messages. **Copy is never generated** — the app makes no LLM calls — so a
tail message is an authored line with the colour substituted into it.

Concretely: `every` is the number of further insights each tail rung costs, defaulting to **3** —
slower than the authored ladder's cadence of 1, because the tail is the long tail and should not
feel like it is racing. The palette and the message pool are ordered lists, both walked by index and
both wrapping.

**Tail rungs must be stable.** The unlock list is history; a rung's wording and colour cannot differ
between two reads of the same record. Everything about a tail rung is therefore derived from its
index — which type, which variant, which message — and never from randomness.

The tail is bounded by the record, not by a limit: the walk stops at the first rung the insight
count does not satisfy, exactly as it does for authored rungs. Nothing is exposed about rungs not
yet reached.

### The room keeps filling

`roomObject` exists and exactly one rung in twelve uses it. Config calls the room "the cheapest
liveness in the whole feature", and it is the only axis with no cap — item types are capped at four
forever, room objects are not.

More authored rungs gain a `roomObject`, and **every third tail rung** places one — slower than the
recolours, so the room fills at a pace that stays surprising over years rather than accumulating
into clutter. A long-running record therefore keeps changing something visible without ever becoming
a collection to complete. `companion-room.tsx` already has the registry (`ROOM_OBJECTS`) and reads it
exactly as `ABILITIES` is read; this is entries, not architecture.

## Architecture

| Thing | Where | Notes |
| --- | --- | --- |
| Ability props | `resources/js/patyourself/blob-renderer.tsx` | fill `ABILITIES` with `extra` renderers for `read`, `carry` |
| Ability poses | `resources/js/patyourself/companion-animations.ts` | add `wave`, `jump`: ambient, `loop: false`, `autoEvery` |
| Ability gating | `resources/js/hooks/use-sprite-clock.ts` | schedule `autoEvery` only for unlocked animations |
| Tail config | `config/companion.php` | new `tail` block: `every`, `variants`, `messages` |
| Tail computation | `app/Services/Companion/CompanionResolver.php` | continues past the authored ladder, index-derived |
| Room objects | `resources/js/patyourself/companion-room.tsx` | entries in the existing `ROOM_OBJECTS` registry |

`CompanionState` does not change shape. A tail rung is an ordinary unlock — `kind: item`, a `name`
from `item_types`, a `variant` from the palette — so every consumer already handles it.

## Error handling

- An ability with no `ABILITIES` entry and no animation still renders the plain body. That is the
  existing contract and it stays: naming an ability in the ladder must never be able to break the
  screen.
- A `roomObject` the config names but the registry does not know is skipped, not drawn as a gap.
  This is the existing behaviour of `ROOM_OBJECTS`.
- An empty or missing `tail` block means the ladder simply ends, as it does today. The tail is an
  addition, never a requirement.
- A variant palette shorter than the number of tail rungs reached wraps rather than running out.

## Testing

- A Blob with `wave` unlocked eventually waves; a Blob without it never does, however long the clock
  runs. This is the gating change and it is the one worth pinning hardest.
- `read` and `carry` draw their prop; an unknown ability draws the body and nothing else.
- The tail: the rung after the last authored one requires the expected insight count; two reads of
  the same record produce byte-identical tail rungs (type, variant and message); the palette wraps;
  an absent `tail` block ends the ladder where it ends today.
- `CompanionLadderTest` still holds — the tail introduces no fifth item type.
- The room draws an object the config names and skips one it does not know.

---

# D2 — Blob notices, and Blob speaks

## Decisions

### A reaction at the moment of logging

Blob currently changes when you next open its screen, so the reward arrives later and somewhere else
than the act that earned it. Recording an outcome fires Blob's existing `reaction` channel in the
32px dashboard corner.

**No copy and no congratulation** — movement only. The existing stage-change announcement is
unaffected and keeps its own rule. Subtlety is the point: the logging flow is the app's most
protected interaction, and a reward that interrupts it is worse than one that waits.

The reaction fires on the outcome being recorded, not on the dashboard being visited, so returning
to the dashboard later does not replay it.

### Blob's remarks, written by the coach

**This reverses a prior ruling, knowingly.** `CompanionAnnouncement` records: "The words are the
app's, written in config and relayed verbatim. The coach never composes the praise: keeping the
voice on this side is what stops Blob turning into a model improvising encouragement at someone."
The owner has overruled it — the coach writes remarks, and Blob relays them.

What this does **not** change: the app still makes no LLM calls, and `NoLlmTest` still holds. Claude
runs outside the app and writes through MCP, which is the existing boundary.

The second rule in that docblock is kept rather than overruled: "a line after every logged breakfast
is wallpaper within a week, and wallpaper is worse than silence." So a remark appears **only on
`/companion`**, one per visit. Never the dashboard corner, never the logging flow, never a
notification.

A remark may be tied to a loop, which is what makes it about the work rather than about nothing. A
remark with no loop is general. Only remarks that are general or attached to an **active** loop are
eligible to be shown, so a paused or archived loop stops talking.

**With no remarks, Blob says nothing.** Silence, not a placeholder and not a default line.

**Tone is the coach's responsibility.** The app cannot check tone and will not pretend to. It
enforces what it can actually verify — **a 280-character cap**, and no exclamation marks, which is an
existing copy rule the ladder already follows. 280 is two sentences with room to breathe, and short
enough that a remark cannot become a paragraph of advice. The tool's own description carries the rest: sentence case,
one or two sentences, never congratulating, no second person keeping score.

Remarks are **append-only**, like every other record in this app. There is no edit and no delete.

## Architecture

| Route / tool | Name | Notes |
| --- | --- | --- |
| MCP tool | `write-blob-remark` | body (required), optional loop; the coach's only write here |
| `GET /companion` | `companion` | gains at most one remark in its props |

- Migration: `companion_remarks` — `user_id`, nullable `intention_id`, `body` (text), timestamps.
  Indexed on `user_id`.
- `CompanionRemarks` service selects the remark to show: eligible set is general remarks plus those
  on active loops; it avoids repeating the one shown on the previous visit, held in the session so
  no new column is needed. With one eligible remark it repeats rather than falling silent.
- The remark is a prop on the companion page, rendered near Blob, in Blob's voice.

**No `trimStrings` exception is needed.** `bootstrap/app.php` carries an exception list keyed on
request field names for text that must be stored verbatim, but MCP arguments never traverse that
middleware — `laravel/mcp`'s HTTP transport feeds the JSON-RPC handler the raw request body, not the
parsed input bag. Adding an entry would be dead config, and a test asserting the behaviour through
an MCP call would prove nothing about the middleware.

## Error handling

- No eligible remark → the screen renders without one. No placeholder, no "Blob has nothing to say".
- A remark whose loop was archived after it was written simply stops being eligible. It is not
  deleted; history is append-only.
- A body over the cap, or containing an exclamation mark, is rejected by the tool with a message
  naming the rule, so the coach can correct and retry.
- A remark naming a loop the caller does not own is rejected. This is a write on the MCP surface and
  is authorized like every other one.

## Testing

- A remark tied to an active loop is eligible; the same remark on a paused or archived loop is not;
  a general remark always is.
- Two consecutive visits do not show the same remark when more than one is eligible, and do when
  only one is.
- An account with no remarks renders the companion screen with no remark and no placeholder text.
- The tool stores the body **verbatim**, including surrounding whitespace, and rejects a body with
  an exclamation mark or over the cap.
- A remark against another user's loop is refused.
- Logging an outcome fires the corner reaction once; revisiting the dashboard does not replay it.

---

# D3 — Sprites

`config('companion.renderer')` is already a flag: `svg` ships, `sprite` was never written. The
clock/renderer seam is clean — `use-sprite-clock` knows timing and nothing about frames,
`blob-renderer` knows frames and nothing about timing — so the architecture already did the hard
part.

**Deferred, deliberately, and sequenced last.** D1 draws ability props and room objects in SVG, and
switching to sprites later means drawing them again. That was weighed: the SVG renderer already
works, a book and a bookshelf are cheap to redraw, and sequencing sprites first would mean nothing
visible improves until a large piece of art exists.

D3 is gated on pixel art, not on engineering. It gets its own spec when there is art to render.

---

## Out of scope

- **Any change to what earns a rung.** The triggers stay `logs` and `insights` exactly as they are.
- **A stored companion table or high-water mark.** `CompanionResolver` is a pure read over the
  record and stays one. The known edge case — deleting an outcome can take an item back off Blob —
  remains deliberately unsolved, as its docblock states.
- **Decay, expiry or regression of any kind.**
- **Naming anything not yet unlocked**, in any surface, including the tail.
- **Editing or deleting a remark.**
- **Remarks anywhere but `/companion`.**
- **A fifth item type.** The cap is data and `CompanionLadderTest` asserts it.

## Assumptions

- Single user in practice, so no migration or compatibility burden for other accounts.
- The connector is expected to be working for remarks to exist at all. Blob falling silent when it
  is not is the designed behaviour, not a failure state.
- Pixel art for D3 does not exist yet and is not assumed to arrive on any schedule.
