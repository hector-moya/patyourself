# Blob — Phase E, the world

Phase D gave Blob a body, a voice and pixels. It has been standing in a room since the beginning
without anyone asking how it got there.

This phase answers that, and in doing so gives the feature somewhere to go for years rather than for
one more release.

**Blob arrives in a forest knowing nothing, and learns how to live because someone kept a record.**
Logging is not a trigger that dispenses rewards — it is the teaching. Over a long record Blob learns
to fend for itself, makes tools, and eventually builds somewhere to live.

Three sub-phases, each independently shippable:

- **E1 — the forest.** The world Blob actually stands in, drawn in pixel art at Blob's own angle,
  lit by four times of day. No building yet.
- **E2 — tools and materials.** Blob learns to use what is around it. Things accumulate.
- **E3 — shelter.** A lean-to, then a hut, then the cabin — which is the room that already exists.

E1 is specified here. E2 and E3 have their decisions recorded below so that when they arrive they are
art and config rather than architecture, but they are not designed in detail yet.

---

## The frame

The existing mechanic already says a `failed` outcome advances Blob exactly as far as a `completed`
one, because the behaviour being rewarded is recording. What was missing was a reason for that to
feel like anything. Now there is one: **a creature learns to survive because you wrote down what
happened.** The reward is reciprocal rather than transactional.

This resolves a tension an earlier draft of this document could not. It argued that a finished
shelter contradicts the absurd, because Sisyphus does not arrive. That was the wrong reading. Camus'
point is not that nothing is ever completed — it is that the labour is enough. A hut becoming a cabin
is not arrival, it is more living. **The arc can carry milestones without having an end.**

### The rule this must never break

"Learns how to live" sits one step away from instructional. The moment the app implies that *you*
should also be building a cabin, it becomes self-help with a mascot, and it breaks the rule this
feature has held since Phase A: never say how well the user is performing.

**Blob's life stays Blob's, and is never a mirror held up to the person reading.** The existing voice
already clears this bar — *"Blob can walk. Slowly, and so far in one direction only."* is a creature
learning, with no lesson attached. Every rung this phase adds is held to the same standard, and
`CompanionVocabularyTest` still scans for the words that would give it away.

## Decisions

### Nothing is named before it exists

Unchanged from Phase A, and load-bearing here in a way it has not been before. A crafting progression
is a checklist in its natural state: the moment the app says Blob needs rope, the feature becomes a
to-do list wearing a costume.

So the app never states a plan, shows a blueprint, or names a material Blob does not have. **You learn
what happened, never what is coming.** One morning there is a wall that was not there the day before.

Inference is welcome — a pile of logs beside a half-built frame says plenty without the app saying
any of it. What is forbidden is the app doing the saying.

### Today's room is the destination, not the thing being replaced

The room Phase D built has a bookshelf, a window, a lamp, a rug and a plant. That is a **cabin**, and
Blob has not built it yet.

So the arc runs:

> forest → lean-to → hut → **cabin (the room that exists today)** → whatever comes after

Nothing already earned is discarded or reassigned. The five room objects are furniture in a building
Blob has not made, waiting inside a scene nobody has reached. That preserves the rule that an object
arrives with the thing that earned it and never leaves — the objects were never the problem, the
missing building was.

**The early shelters have no interior.** A lean-to is not somewhere you go inside; it is another thing
standing in the forest. The first scene *change* — outside becoming inside — is the cabin, which makes
it an event rather than one of four transitions.

### A scene is a stage, its contents accumulate, its tools are anchored layers

Phase D settled three ways a thing can appear on screen. A scene needs exactly those three and no
fourth:

| Pattern | Established by | Used here for |
| --- | --- | --- |
| **Stage** — one of N, latest wins | `formFor()` over `features` | which scene you are in; the shelter's build stage; the part of day |
| **Accumulate** — arrives, deduped, never leaves | `CompanionState::roomObjects()` | logs, stumps, a sapling, tools left leaning on things |
| **Anchored layer** — drawn when owned, tracks the body | worn items and ability props | the axe in Blob's hand |

That the forest needs no new mechanism is the evidence the Phase D architecture was right. `forest →
lean-to → hut → cabin` is the same shape as `blob → legs → arms`, and the compositor does not know the
difference.

### Light is a render-time filter, not an asset axis

Four parts of day times four shelter stages times every object in the scene is a multiplication, and
it is the same trap Phase D walked into with accessory colours. The answer is the same: **bake only
what genuinely differs, and filter the rest.**

- **Backdrops are baked** — four of them. Sun position, sky gradient and cloud colour are real
  geometry rather than a hue shift, and no filter produces them.
- **Everything in front of the backdrop is generated in neutral light** and tinted at render by one
  filter over the whole foreground group.

Cost is `4 + N` rather than `4 × N`. A new shelter stage is one transparent PNG, not four.

**Blob is inside that filter.** This reverses `blob-renderer.tsx`'s ruling that Blob's body colour is
fixed — *"a Blob that changes colour with its surroundings is a different Blob."* That rule was
written about light mode versus dark mode, where the surroundings are application chrome and Blob
should indeed ignore them. Standing outdoors at dusk is a different question, and a creature lit
differently from the world it is standing in reads as pasted on. The docblock is corrected rather than
quietly contradicted.

### The scene is derived, never stored

`CompanionResolver` remains a pure read. Which scene to draw is a function of the record, exactly as
`formFor()` picks a body from `features` — no shelter earned means the forest. No new table, no
migration, and the known edge case about deleting an outcome stays exactly as known as it was.

### An existing record starts where it has already arrived, and can never be sent back

A record deep enough to have earned cabin furniture has earned the cabin. Mapping is by the triggers
already banked rather than by a migration: the cabin's threshold sits at a count an established record
has passed, so it resolves indoors on the first read and nothing goes out of view.

**The cabin's threshold never moves once set.** E2 and E3 insert `lean-to` and `hut` *below* it and
never push it later. This is the rule that matters, because a threshold raised in a later phase would
walk an existing record backwards from a cabin to a hut — and nothing in this feature has ever
regressed.

That has an honest consequence worth stating rather than discovering later: **the arc up to the cabin
is short.** A record already 35 outcomes deep is past almost any threshold a first shelter could
reasonably sit at, so forest → lean-to → hut → cabin is a matter of weeks rather than years. The years
live *after* the cabin, which is what "it finishes, then something else starts" was always going to
mean. The alternative — a long arc calibrated for a fresh record — puts an established one back
outdoors, which is the regression this section exists to prevent.

The forest is therefore what a **new** record sees. `COMPANION_SCENE` overrides the derived scene so
the forest can be looked at on demand — a development affordance rather than a user-facing setting,
because a scene the reader can choose is a setting rather than a consequence of the record, and every
other part of this feature refuses to be that.

### What an existing record actually sees change in E1

Not "nothing", which an earlier draft of this section claimed and which is wrong on two counts:

- **Blob is now lit by the part of day**, indoors as well as out. That is the reversal recorded above
  and it applies wherever Blob stands.
- **There is a fourth part of day.** The room has tinted its wall and window by `day`/`dusk`/`night`
  since Phase B; adding `sunrise` gives the early morning its own pair of colours.

Both are small and both are the point. What does *not* change is the room's contents: every earned
object is still returned and still drawn.

### Nothing new earns a rung

`logs` and `insights` stay exactly as they are. Experience is the act of recording — more experiments
recorded, more outcomes recorded — and this phase adds no third trigger, no currency and no
inventory count.

## E1's scope

- The forest backdrop, at Blob's own `low top-down` angle, in four parts of day.
- Trees that move, animated as separate layers over a static backdrop.
- The part-of-day config grows from three entries to four.
- The foreground tint, Blob included.
- The scene registry, with two members: `forest` and `cabin` (today's room, contents unchanged).
- The cabin's threshold, set once and never moved again.

**Not in E1:** any tool, any material, any shelter stage between forest and cabin, and any change to
what the room contains.

A consequence worth being plain about: with only two scenes and the cabin's threshold below an
established record, **E1's forest is not what the owner's account will show.** It is reachable through
`COMPANION_SCENE` and it is what a new record would see. E1 buys the architecture, the art and the
lighting; E2 and E3 are what put anyone in front of it for real. That was the accepted trade when the
alternative was taking four earned objects out of view for a release.

## Architecture

| Thing | Where | Notes |
| --- | --- | --- |
| Scene selection | `app/Services/Companion/CompanionState.php` | derived from unlocks, a pure read |
| Part of day | `config/companion.php` | the existing `room` block grows to four entries |
| Scene registry | `resources/js/patyourself/scenes.ts` | backdrop set, anchor table and layer list per scene |
| Backdrops and foliage | `resources/js/patyourself/scenes/` | committed PNGs, as the sprite sheets are |
| The compositor | `resources/js/patyourself/companion-room.tsx` | draws a scene; today's room becomes one entry rather than the only thing it knows |

`CompanionResolver` and every migration are untouched.

### Why the trees do not get their own clock

`use-sprite-clock` already runs exactly one `requestAnimationFrame` loop for the whole application,
with components subscribing to it, precisely so two Blobs on one screen cannot drift out of phase.
Foliage subscribes to the same loop for the same reason, and reads its own frame rate from its own
entry — the clock has read `frames`/`fps` per animation since Phase C.

A tree swaying at 3fps beside a Blob breathing at 2fps is two entries in a table, not two loops.

### The generation recipe

Recorded before anything is generated, because two constraints decide the whole approach.

**`animate_image` caps at 256×256, with `width × height × frames ≤ 524288`.** A 256px frame allows
eight frames. `create_image_pro` will produce backdrops at 512×512 or 688×384. **A full-width backdrop
therefore cannot be animated as one image**, and this is why the trees are separate layers rather than
part of the sky.

That constraint improves the result rather than merely limiting it: swaying every pixel at once reads
as an earthquake, while three or four trees moving at slightly different rates reads as wind. Layering
also buys parallax for nothing.

**`create_image_pro` has no `view` parameter.** The angle lives in the description and can be verified
only by looking. Since the perspective mismatch between a side-on room and a `low top-down` character
is exactly what this phase exists to fix, **the angle is checked on the first backdrop, not the
fourth.**

Backdrops: `create_image_pro`, `no_background: false`, 20-40 generations each. Generate sunrise
first, then pass it as `style_image_url` for the other three so the four agree — the same technique
that kept the three Blob forms coherent, where describing the look again and hoping did not.

Foliage: `create_map_object` accepts `view: "low top-down"` and a `background_image` for style
matching, then `animate_object` in `v3` mode. A 64×64 eight-frame sway is one generation.

Budget: roughly 150-300 generations of the ~1890 remaining.

## Error handling

- A scene the registry does not know falls back to the earliest scene rather than rendering nothing.
  Naming a scene must never be able to break the screen, which is the existing contract for item
  types, room objects and animations.
- A part of day the config does not name falls back to `day`.
- A foliage layer with no animation is drawn static rather than skipped. A still tree is a tree; a
  missing one is a hole.
- The backdrop failing to load leaves the scene's flat base colour behind it, so Blob never stands on
  nothing.

## Testing

- The scene is derived from the record: a record with no shelter resolves to the forest, one with a
  cabin resolves indoors, and an unknown scene name falls back rather than throwing.
- An established record resolves indoors on its first read, and none of its earned room objects stop
  being returned. This is the regression the mapping exists to prevent, so it is pinned hardest.
- All four parts of day resolve, including the wrap past midnight the existing three-part config
  already handles.
- The foreground tint applies to Blob as well as the scene — the reversal above, made visible.
- Exactly one animation loop still runs with a Blob and foliage on screen together. Phase C's "exactly
  one" test already exists and must keep passing with new subscribers.
- **The visual check is a render, not an assertion.** Four backdrops, every foliage frame, and Blob
  standing in front of them at both shipping sizes. Phase D shipped four defects past a green suite —
  items 32px off the body, an anchor measured from the wrong pixel, glasses that erased the eyes, and
  props dropped entirely — and every one was found by looking. The angle in particular can only be
  judged by eye.
- Every new guard is sabotaged before it is trusted. A guard whose red cannot be produced is
  decoration.

## Out of scope

- **Tools, materials and shelters.** E2 and E3.
- **Any change to what earns a rung.**
- **Any change to the room itself.** It becomes one scene among two and is otherwise untouched.
- **A user-facing scene switcher.** The scene is a consequence of the record.
- **Moving the accessories to Pixel Lab.** They stay procedural rects; the wish to keep every asset in
  one place is noted and is not this phase's problem.
- **Naming or previewing anything not yet reached**, in any surface.

## Assumptions

- Single user in practice, so the mapping of an existing record needs no migration for other accounts.
- Pixel Lab remains reachable. Backdrops are committed, so a later outage cannot affect the app.
- Generation quota is not the constraint; review effort is. Every backdrop needs a human look, and the
  first one needs one before the other three are generated at all.
