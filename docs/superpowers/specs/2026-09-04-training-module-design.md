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

### Having a template is what makes a loop a training loop

There is **no `kind` column and no flag**. If the action being logged has exercises attached, the log
screen offers the tracker; otherwise it offers done/missed/skipped as it does today.

One fact, one source. A flag would be a second fact that can contradict the first — a loop marked
`training` with no routine, or a routine on a loop marked `habit` — and that is the class of bug this
codebase keeps finding in its own reviews.

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

### Sets hang off the log, not the occurrence

A session logged as **failed** can still carry the two exercises you actually managed before your back
went. That is a record, and records are the point. Attaching sets to the occurrence instead would make
"what happened" independent of "how it went", which is precisely the pair this app keeps together
everywhere else.

## Architecture

| Thing | Where |
| --- | --- |
| Exercise catalogue | `exercises` — seeded list plus user-added |
| The routine | `action_exercises` — action, exercise, position, target sets, target reps |
| What happened | `performed_sets` — action log, exercise, set number, reps, weight |
| Reads | `App\Services\Training\` — last performance, exercise history |
| Screens | the log screen gains a tracker; one progression screen |

`Intention`, `Strategy`, `Action`, `Occurrence` and `ActionLog` gain **no columns**.

### Notes on the tables

- **`exercises`** has a nullable `user_id`. Rows with `user_id` null are the shared seeded catalogue,
  visible to everyone; a row with a `user_id` is that person's own addition. **Nothing is copied per
  user** — seeding once at migration time is what makes "start lifting immediately" and "add the machine
  my gym has" both work without a per-user duplicate of a hundred barbell movements.
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

## The log screen

```
Upper A · Wednesday 10 September

Bench press                     last: 60kg · 10 / 10 / 8 · 2 Sep
  target 3 x 10
  [    ] kg    [   ] [   ] [   ]

Barbell row                     last: 50kg · 10 / 10 / 10 · 2 Sep
  target 3 x 10
  [    ] kg    [   ] [   ] [   ]

                                        [ Done ]  [ Missed ]
```

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
- Blob's `logCount` and `insightCount` move identically for a training log and a plain one.
- Sets survive a strategy revision that changes the routine.
- A failed session can still carry performed sets.
- An action with no template shows no tracker; one with a template shows it. Both proven by mutation,
  not by rendering once and reading the class names.
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
