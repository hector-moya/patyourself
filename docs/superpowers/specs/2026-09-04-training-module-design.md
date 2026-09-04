# Training — sessions, exercises and what actually got lifted

The app tracks whether you did the thing. This adds what happened while you did it, starting with
lifting.

**A training loop is an ordinary loop.** It has a cue, a craving, a response and a reward; it runs a
versioned experiment; it produces occurrences you log with a reason when they fail. What makes it a
training loop is that its actions carry a list of exercises — nothing more.

That sentence is the whole design. Everything below follows from refusing to make training a second
kind of thing.

---

## The frame

Every other lifting app is built to prescribe and to grade: *add 2.5kg, new PR, you are 12% stronger,
suggested weight 67.5kg.* This one records and shows, and the decisions stay with the person lifting.

That is not a limitation adopted for purity. It is the same position the rest of the app already
holds — the notebook does not tell you what to conclude, and Blob never says how you are doing — and
holding it here is what stops the app becoming two apps with different opinions about the person
using them.

It also happens to be cheap. **The only thing a suggestion would give you is last session's numbers,
and those can simply be on the screen.**

## Decisions

### No new top-level domain

Training reuses `Intention → Strategy → Action → Occurrence → ActionLog` exactly as it stands. It adds
three tables that hang off that spine and changes none of it.

What that buys, none of which has to be built:

| Already works | Because |
| --- | --- |
| Scheduling and due dates | `Occurrence` already makes each occasion a row |
| Catch-up | logs are logs |
| Email reminders | reminders are per action |
| The strategy timeline | a routine change is a strategy revision |
| The MCP write surface | logging an outcome is unchanged |
| Blob | `logCount` and `insightCount` are unchanged |

The alternative — a `TrainingSession` domain beside the existing one — was rejected. It would have
duplicated scheduling, due-dates, catch-up and reminders, and left two answers to "what is due today".

### The routine is the Strategy; session types are Actions

A routine like *three days a week, two upper and one lower, for eight weeks* is a **hypothesis about a
behaviour**, which is exactly what a `Strategy` already is. Each session type is an `Action` under it:

```
Intention   "Get to the gym"
 └─ Strategy v1   "3 days a week, U/U/L — 8 weeks"
     ├─ Action "Upper A"  Mon   template: bench 3x10, row 3x10, ...
     ├─ Action "Upper B"  Wed   template: ...
     └─ Action "Lower"    Fri   template: ...
```

**Changing your programme is a strategy revision.** The app already versions those, already records the
reason, and already refuses to rewrite history — so training history survives a programme change by a
mechanism that exists and is tested, rather than by one written for this module.

Rejected: a rotating single action ("Train", cycling A/B/L). It models alternating programmes more
naturally but turns "what is due Wednesday" from a row into a calculation, and every feature that reads
occurrences would have to learn the new answer.

Rejected: a `Programme` entity with weeks, deloads and periodisation. Faithful to how real programmes
work, and duplicative of strategy versioning. Revisit only if strategy revisions prove too coarse in
practice.

### `workflow: 'gym'` is how a loop reaches this module

**Governed by `2026-09-05-workflow-architecture-design.md`, which this module is the first instance
of.** Read that first; only what is specific to lifting is repeated here.

A loop carries `workflow: 'gym'`, persisted, chosen from the registry. That is what offers the exercise
setup on its actions and the tracker when logging. This module's two extension points:

| Point | Holds |
| --- | --- |
| Config on an `Action` | the exercise template — 3 × 10 bench, 3 × 10 row |
| Record on an `Occurrence` | the sets performed, with weights |

Two earlier drafts of this section were wrong, and both are worth keeping because they are the kind of
wrong that survives review.

**"Having a template is what makes it a training loop"** was self-consistent and unimplementable: if
the tracker only appears once exercises exist, nothing ever offers you the chance to add them.

**Free-form tags** fixed that and introduced a weaker problem. A tag is typed — `Gym`, `gimnasio`, a
trailing space — and the routing then fails silently with nothing on screen to say why. A registry name
is chosen, spelled by the code, and testable, which is the pattern `SCENES`, `ROOM_OBJECTS` and
`ANIMATIONS` already use here.

### Record, never prescribe

The log screen shows what you lifted last time for each exercise. It does not suggest a weight, does
not announce a personal best, does not compute an estimated one-rep max, and does not show a percentage
or a trend line described as progress.

The target sets and reps *are* shown, because **you wrote them** — they are part of the routine you
authored, exactly as a strategy's hypothesis is. The app displaying your own plan back to you is not
the app stating a plan.

`CompanionVocabularyTest` already forbids most of the opposing register (`percent`, `congratulation`,
`level up`, `completion rate`). This module's source files go on that list.

### Blob's economy is untouched

**One session is one log**, whatever is inside it. A session containing twenty sets advances Blob
exactly as far as ticking off a glass of water, and Blob is never told which it was.

This is the load-bearing decision for everything that comes after. The moment a module can mint fuel
faster by being more granular, the app has to take a position on what each module is *worth* — and
every new module reopens the argument. Refusing the second currency once, here, settles it for
running, cycling, weight and whatever follows.

The cost, stated plainly: a hard training session and a trivial habit are worth the same to Blob. That
is correct under this app's own premise — *recording is the teaching* — but it will feel wrong on a day
when you deadlift and it is worth what flossing is worth.

### Sets hang off the occurrence, not the log

**The occurrence is the session. The log is the verdict on it.**

This reverses an earlier draft, and the interaction is what forced it. Sets are ticked off *during* a
session — weight in, set done, rest, next — which means they are written long before anyone presses
Done or Missed. At that moment there is no `ActionLog` to attach them to.

Creating the log early to hold them is the tempting fix and the wrong one: `logCount` is Blob's fuel,
so a log that exists from the first set would pay you for walking into the gym and putting the bar
down. The economy decision above is only worth making if nothing quietly undoes it.

The occurrence already exists the moment the session falls due, so it is the natural home. Nothing is
lost by moving them: `Occurrence` and `ActionLog` are one-to-one, so a session logged as **failed**
still carries the two exercises you managed before your back went — which was the property the earlier
draft was protecting.

One consequence to handle rather than discover: an **unscheduled** session — walking into the gym on a
rest day — has no occurrence. It needs one created on the spot, which is the same thing the app already
does for a log against an unplanned occasion.

## Architecture

| Thing | Where |
| --- | --- |
| Routing | `intentions.workflow` — nullable, registry-backed (architecture spec) |
| Exercise catalogue | `exercises` — imported public-domain set plus user-added |
| The routine | `action_exercises` — action, exercise, position, target sets, target reps |
| What happened | `performed_sets` — **occurrence**, exercise, set number, reps, weight |
| Reads | `App\Services\Training\` — last performance, exercise history |
| Screens | a session screen, an exercise screen, one progression screen |

`Strategy`, `Action`, `Occurrence` and `ActionLog` gain **no columns**. `Intention` gains exactly one —
`workflow` — and that belongs to the architecture rather than to this module: it is added once, by the
workflow spec, and every later module reuses it rather than adding its own.

### Notes on the tables

- **`exercises`** has a nullable `user_id`. Rows with `user_id` null are the shared catalogue, visible
  to everyone; a row with a `user_id` is that person's own addition. **Nothing is copied per user** —
  seeding once is what makes "start lifting immediately" and "add the machine my gym has" both work
  without a per-user duplicate of eight hundred barbell movements.

### Where the catalogue comes from

Imported once from [free-exercise-db](https://github.com/yuhonas/free-exercise-db) — roughly 800
exercises with instructions, primary muscle and equipment, released into the **public domain**. Public
domain rather than one of the larger sets specifically because it carries no obligations if this app
ever stops being personal.

The import is a **seeder, not a runtime call.** A third-party fitness API in the path of the gym screen
would rate-limit, change its pricing, or simply be unreachable from a basement gym with no signal —
and this codebase already holds the opposite principle for its art: *sheets live in this repository, so
a Pixel Lab outage can never affect the running app.*

**Images are fetched on demand, not bulk-imported.** Metadata for 800 exercises is about a megabyte;
their images are hundreds. A routine uses perhaps twenty. So an image is fetched and stored locally the
first time an exercise is added to a template, and never fetched again — `exercises.image_path` is null
until then, and a null image is simply an exercise without a picture, never a broken one.

Committing the full image set was rejected on deploy cost: it would dwarf the codebase, and Forge
deploys on this project already fail at `npm ci` with the OOM killer on a marginal box.
- **`performed_sets.weight`** is stored in kilograms as a decimal. One unit in the database, converted
  at the edge if pounds are ever wanted. Storing whatever the user typed alongside a unit column is how
  a progression read ends up comparing 60 to 132.
- **Body-weight exercises** record `weight` as null rather than zero. Null is "not applicable"; zero is
  a weight, and a chart cannot tell them apart.
- **Sets are rows, not a JSON blob.** Progression is a query over them, and a blob would have to be
  unpacked in PHP on every read.
- **`target_reps` is a single integer, not a range.** Real routines say "8–12"; v1 says 10. A range is
  two columns and a display rule, and it can be added without touching anything already written. Chosen
  deliberately rather than overlooked.

## The screens

Two levels, because that is how a session is actually used: one exercise at a time, with rest between
sets. A single scrolling page of every field at once is a form; this is a checklist you work down.

**The session** — the exercises in order, with how far through each one you are.

```
Upper A · Wednesday 10 September

  Bench press          3 x 10     ✓ ✓ ·
  Barbell row          3 x 10     · · ·
  Lat pulldown         3 x 12     · · ·
  Face pull            3 x 15     · · ·

                          [ Done ]   [ Missed ]
```

**The exercise** — tapped from that list.

```
Bench press                              target 3 x 10
                              last  60kg · 10 / 10 / 8 · 2 Sep

   weight     reps      done
   [ 60 ]kg   [ 10 ]     ☑
   [ 60 ]kg   [ 10 ]     ☑
   [ 60 ]kg   [    ]     ☐

Lower to the chest under control, press to lockout.
```

The last line is the imported instruction, and the picture sits beside it once one has been fetched.

Weight carries down from the set above, because the common case is three sets at the same weight and
retyping it twice is the friction that stops people logging. It is a default, not a target — every
field stays editable, and nothing anywhere suggests the number should go up.

The outcome buttons are the ones that already exist. Recording sets is optional — a session logged with
no sets at all is still a logged session, because the habit is the thing being tracked and the detail is
a bonus.

**Entering sets does not decide the outcome.** You still press Done or Missed, and the reason field
still appears on a failure exactly as it does now. The two are deliberately independent: filling in
three sets and then marking the session missed because you cut it short is a real thing that happens,
and an app that inferred "done" from the presence of data would be overruling the person who was
there. It would also quietly change what `logCount` means, which is the one number this design promises
not to touch.

## Progression

One screen, per exercise, newest first: what you lifted, for how many reps, on what date. No chart in
v1 — there is nothing to chart until there is history, and a sparkline over three sessions is
decoration.

## Error handling

- An action with no template logs exactly as it does today. The tracker is additive.
- An exercise deleted from the catalogue keeps its performed sets; history does not vanish because a
  name was tidied up. Deletion hides it from new templates only.
- A set with reps but no weight is valid (body weight). A set with neither is not recorded.
- A strategy revision that drops an exercise leaves earlier sets intact and readable — the same
  never-rewrite-history rule the rest of the app holds.

## Testing

- A training loop schedules, falls due, appears in catch-up and logs **exactly like any other loop** —
  the regression that matters, since the claim is that nothing was special-cased.
- One session produces exactly one `ActionLog`, no matter how many sets it carries. Pinned hardest:
  this is the economy decision, and it is the one a future module would be tempted to break.
- **Recording sets creates no log.** Tick every set of every exercise, press nothing, and `logCount` is
  unchanged — this is the guard that stops the tracker quietly minting Blob's fuel, and the reason sets
  moved off the log in the first place.
- Blob's `logCount` and `insightCount` move identically for a training log and a plain one.
- Sets survive a strategy revision that changes the routine.
- A failed session can still carry performed sets.
- A loop with `workflow: null` offers no exercise setup; one with `workflow: 'gym'` does. An action with
  no template shows no tracker; one with a template shows it. Both proven by mutation, not by rendering
  once and reading the class names.
- A logged session with no occurrence creates one rather than failing.
- An exercise whose image was never fetched renders without one and does not error.
- The new source files go into `CompanionVocabularyTest::sourceFiles()`, and the list is proven to bite.

## Out of scope

- **Periodisation, deloads and progressive-overload rules.** Strategy revisions cover programme changes.
- **Supersets, drop sets, tempo, RPE, rest timers.**
- **1RM estimates, volume totals, strength scores, PR detection.** See "record, never prescribe".
- **Charts.** After there is history.
- **Logging a session over MCP.** The natural next step, and not needed to find out whether the module
  earns its place.
- **Running and cycling.** See below — they are a different shape and should not be forced into this one
  before their own design.

## What this buys later

Verified 2026-09-04, because it shapes the sequencing:

- **Strava** — OAuth 2.0, server to server, no app required. Needs a Strava subscription for API access
  at Standard Tier, and self-upgrade caps at 10 athletes. Routing data through third-party
  intermediaries is no longer permitted; direct integrations are unaffected.
- **Withings** — OAuth2, webhooks for push rather than polling, and full history rather than only new
  readings. Server to server.
- **Samsung Health** — **not available server-side.** The legacy SDK is deprecated and the replacement
  requires fresh partner approval; the practical route is Health Connect, which is Android and
  on-device. This is the only one of the three that would force a native app.

A run imported from Strava is a *record of something that happened*, not a session you plan and log —
so it is a different shape from a gym session and gets its own design. What this module settles for it
in advance is the part that matters: **what an imported activity is worth to Blob.** One activity, one
log, the same as everything else.

## Assumptions

- Single user in practice. The exercise catalogue is user-scoped anyway, because that costs nothing now
  and a shared catalogue would need a migration later.
- Kilograms. Pounds are a display concern if they ever arrive.
- The person lifting knows what they want to lift. This module is a notebook, not a coach.
