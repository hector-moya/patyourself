# The gym module

The gym is the first — and so far only — module plugged into the workflow seam. A loop that names
`gym` gains a **routine** on each of its actions (what the session is meant to contain) and
**performed sets** on each of its occasions (what it actually contained).

This is the current-state reference: how it works today, why it works that way, and which decisions
must not be reopened. The frozen design record is
`docs/superpowers/specs/2026-09-04-training-module-design.md`, with three frozen plans
(`2026-09-06-gym-spine`, `2026-09-07-gym-recording`, `2026-09-09-gym-progression`). **Where this file
and a spec disagree, this file is right.**

Read `docs/WORKFLOWS.md` first — this file is the worked example of that seam. `docs/NOTEBOOK.md` has
the occasion model both depend on.

**This file is not on `CompanionVocabularyTest::sourceFiles()` and must not be added.** §8 states what
this module may not say, which cannot be written without naming the banned words, so scanning it would
fail on its own subject matter. `docs/BLOB.md` carries the same exemption for the same reason.

---

## 1. The one sentence

**Record, never prescribe.**

The module writes down what was lifted. It suggests no weight, detects no record, computes no 1RM, no
volume total, no fraction of a target, and names no trend. Showing what was lifted is recording;
saying what it means is coaching, and the coach is in Claude, not in here.

## 2. Three gym sessions a week is one loop

An upper-body Monday and a lower-body Thursday are **two actions on one loop**, not two loops. The
cue, craving, response and reward describe one behaviour — going to the gym instead of going home —
and the experiment and its verdict belong to that behaviour. Each action carries its own routine,
which is what makes the two sessions different.

Splitting them into separate loops fragments one record into three and asks the person to write the
same chain three times.

## 3. The two tables

| Table | Site | Keyed to | Columns |
| --- | --- | --- | --- |
| `action_exercises` | **config** | `actions` | `action_id`, `exercise_id`, `position`, `target_sets`, `target_reps` |
| `performed_sets` | **record** | `occurrences` | `occurrence_id`, `exercise_id`, `set_number`, `reps`, `weight` |

Unique indexes: `(action_id, position)` and `(occurrence_id, exercise_id, set_number)`. Foreign keys
`cascadeOnDelete` to the owning action/occurrence and **`restrictOnDelete` to the exercise** — deleting
a catalogue row can never take the sets recorded against it with it. History does not vanish because a
name was tidied up.

Two column decisions worth keeping:

**`weight` is kilograms, and null means body weight — "not applicable", never zero.** Zero is a
weight, and a progression read cannot tell the two apart. The set grid renders them differently.

**`target_reps` is a single integer, not a range.** v1 says "10", not "8-12". A range is two columns
and a display rule, addable later without touching anything written here.

And the routine deliberately holds **no weight**. What was lifted is a fact about an occasion and
lives on `PerformedSet`; a target weight on the prescription would be a recommendation.

## 4. The catalogue

`exercises` — 876 imported rows plus whatever the user has added.

- Imported from `database/data/exercises.json` (~1 MB, committed), a one-time export of
  [free-exercise-db](https://github.com/yuhonas/free-exercise-db), licensed under The Unlicense.
  **Read from disk, never fetched at runtime.**
- A row with no `user_id` is shared; a row with one is that person's own addition. `Exercise::availableTo()`
  is the scope, and it is applied on **every** read and write path that names an exercise id.
- The source's `images` key is deliberately ignored — v1 ships no exercise images, and every imported
  row gets an explicit null `image_path`.

**The seeder must be run explicitly on deploy:**

```bash
php artisan db:seed --class=ExerciseCatalogueSeeder
```

It is idempotent on `external_id` (the source's own slug, e.g. `3_4_Sit-Up`), so re-running updates in
place and leaves the user's own additions alone — "run it on every deploy" is a perfectly good rule. It
is kept out of the deploy script because `DatabaseSeeder` also creates a `Test User`, so the generic
`db:seed` must never run in production.

**This step was documented correctly and simply never run on production for weeks**, and every exercise
search returned nothing the whole time. Nothing errored. `docs/DEPLOY-FORGE.md` §14 is where that class
of gap is now tracked.

`database/data/exercises.json` is deliberately **excluded** from the vocabulary list: it is a committed
third-party export containing arbitrary prose that no vocabulary rule can constrain.

## 5. The screens and the routes

| Route | Name | What it is |
| --- | --- | --- |
| `POST actions/{action}/exercises` | `actions.exercises.store` | Add a row to the routine |
| `PATCH actions/{action}/exercises/reorder` | `actions.exercises.reorder` | Rewrite the whole order |
| `DELETE actions/{action}/exercises/{actionExercise}` | `actions.exercises.destroy` | Drop a row |
| `GET exercises` | `training.exercises.index` | Catalogue search, **JSON**, for the picker |
| `POST actions/{action}/session` | `training.session.materialise` | Begin recording |
| `GET occurrences/{occurrence}/session` | `training.session.show` | The session screen |
| `POST occurrences/{occurrence}/sets` | `occurrences.sets.store` | Record one set |
| `GET occurrences/{occurrence}/exercises/{exercise}` | `training.exercise.show` | The exercise screen |
| `GET exercises/{exercise}/progression` | `training.progression.show` | One movement across sessions |

Four surfaces:

**The routine editor** (`routine-editor.tsx`) — registered at `WORKFLOWS.gym.config`, drawn by
`WorkflowConfig` inside the loop screen's action layer. An action's configuration belongs beside the
action, not on a screen of its own. The catalogue picker queries `training.exercises.index` as the user
types — 876 rows cannot be a prop on the loop screen. Reordering posts the **whole** order, because
`ReorderRoutine` refuses a payload that does not name every current row.

**The gym record slot** (`gym-record.tsx`) — registered at `WORKFLOWS.gym.record`, drawn inline by
`WorkflowRecord` on the dashboard and catch-up cards. Its whole job is one line that gets the user to
the session. With an occurrence in hand that is a plain link; without one — a cue-anchored action whose
slot does not exist yet — it must **write** first (`training.session.materialise`), then read the
occurrence id off the landed page and hand it to the host via `onOccurrenceMaterialised`.

**The session screen** (`pages/training/session.tsx`) — a dedicated Inertia page, not something the
slot draws inline. One occasion's routine, its targets, how many of each are already recorded, and the
plain verdict controls.

**The exercise screen** (`pages/training/exercise.tsx`) and **the progression screen**
(`pages/training/progression.tsx`) — what to lift and what was lifted last time, then that movement
across every session. The progression screen is keyed on the **exercise alone**, not on an occasion:
it is the one screen in the module about a movement rather than about a session, and is reachable
outside a session for that reason.

## 6. Reads and writes

### Writers — `app/Actions/Training/`

| Class | What it does |
| --- | --- |
| `AddRoutineExercise` | Appends one row. Position is computed here, never accepted from the caller |
| `RemoveRoutineExercise` | Drops one row and renumbers the rest so the list reads 1, 2, 3 |
| `ReorderRoutine` | Rewrites the order wholesale |
| `RecordSet` | Writes one `PerformedSet`. The record site's only writer |

Three shapes repeat, and all three are there for a reason:

**`max(...) + 1` read with `lockForUpdate()` inside a transaction.** Both `AddRoutineExercise`
(`position`) and `RecordSet` (`set_number`) do this. Two quick taps would otherwise both read the
current state, both compute the same next value, and race each other into the unique index — a 500 on
the second one.

**`max`, not a count.** A count is only the next open slot while positions happen to be contiguous,
which would make `AddRoutineExercise`'s correctness depend on `RemoveRoutineExercise` renumbering after
every removal. They were coupled that way once and deliberately are not any more — the renumbering is
presentation, not a correctness dependency.

**Positions are bumped out of the way before being reassigned** in `ReorderRoutine`. Writing final
positions directly, in the target order, collides with `(action_id, position)` the moment a row moves
later in the list than a row still waiting its turn.

`ReorderRoutine` also refuses an `$order` that is not exactly a permutation of the action's current
rows, rather than partially applying it — a silent partial reorder leaves positions the caller cannot
predict. That check depends on which rows the action currently has, which only the database knows, so
it lives in the action rather than in request validation.

**`RecordSet` never touches an `ActionLog`,** however many sets a session racks up. One occasion, one
log, and the verdict is a separate press by a person always. Filling in three sets and then marking the
session missed because it was cut short is a real thing that happens, and inferring "done" from the
presence of data would overrule the person who was there.

**`set_number` continues from what is already recorded** for that `(occurrence, exercise)` pair rather
than restarting at 1. Two sessions on the same day with no verdict pressed between them deliberately
resolve to the same occasion, so a second session recording the same exercise must continue numbering.

### Read models — `app/Services/Training/`

| Class | For | Queries |
| --- | --- | --- |
| `SessionScreen` | The session screen | Constant: routine rows, their exercises, the occasion's sets |
| `LastPerformance` | The exercise screen | Two, whatever the history's size |
| `ExerciseHistory` | The progression screen | One, whatever the history's length |

All three are pure reads. Five properties they share:

**An action with no routine returns empty rather than erroring.** The tracker is additive: an occasion
for a plain action must still log exactly as it always has.

**The exercise is loaded in full, never column-limited.** A `->with('exercise:id,name')` returns null
for `instructions` forever, with the suite still green — the trap this project has been bitten by three
times already.

**Every read is scoped to the user through `intentions.user_id`.** The catalogue is shared, so a bare
read by exercise id would hand one person's training to another. `LastPerformance` scopes to the
occasion's owner; `ExerciseHistory` scopes through the owning loop. `ExerciseController` scopes **both**
the occasion (`Gate::authorize('log', ...)`) and the exercise (`Exercise::availableTo()`), because the
URL names two things and they are two separate questions — without the second, an occasion you own is
enough to render a stranger's private exercise, instructions included.

**Every `ORDER BY` carries a tiebreaker on `occurrences.id`.** Two occasions can share a
`scheduled_for` — different actions, same slot, the same exercise in both routines — and which one wins
"last time" is otherwise whatever the engine returns, **which differs between SQLite here and MySQL in
production**.

**`LastPerformance` excludes the occasion the caller names.** The exercise screen calls it while a
session is in progress, and that occasion's own unfinished sets are not "last time" — they are what the
set grid is already showing on screen.

**`ExerciseHistory` does not filter on whether the occasion was logged.** What was lifted is what was
lifted; the verdict is a separate fact about the occasion and does not decide whether the sets happened.

### Authorization

Two abilities, split on the same line the two extension sites are split on:

| Surface | Gate | Because |
| --- | --- | --- |
| The routine (`RoutineController`) | `ActionPolicy::update` | A routine is part of the standing prescription |
| Sets (`PerformedSetController`), the session, the exercise screen | `log` on the occurrence | A set is a fact about one occasion |

An exercise outside your catalogue is refused as a **404, not a 403** — it does not exist as far as you
are concerned, and a 403 would confirm the id is real.

## 7. Rulings that must not be reopened

| Ruling | Why | Cost if wrong |
| --- | --- | --- |
| **Recording does not log** | One occasion, one log. A verdict is a person's press | An inferred verdict overruling the person who was there; an inflated `logCount` |
| **`weight: null` is body weight, never zero** | Zero is a weight; a progression read cannot tell them apart | Body-weight sets reading as zero-kilogram lifts |
| **The routine holds no weight** | What was lifted is a fact about an occasion | A prescription that recommends |
| **`target_reps` is an integer, not a range** | v1 says "10". A range is two columns and a display rule, addable later | Nothing — this is the cheap direction, kept deliberately |
| **`restrictOnDelete` on `exercise_id`** | A tidied name must not delete the history recorded under it | Sets vanishing with a catalogue edit |
| **Position from `max`, not a count** | A count couples the adder to the remover's renumbering | A routine with a gap colliding on an occupied position |
| **`lockForUpdate()` on both sequences** | Two taps racing into a unique index | A 500 on the second tap |
| **`ReorderRoutine` refuses a partial order** | A silent partial reorder leaves unpredictable positions | Positions the caller cannot predict |
| **Every read scoped to the user, catalogue notwithstanding** | The catalogue is shared; account ownership says nothing about exercise ownership | One person's training shown to another |
| **Every sort tiebroken on an id** | Two occasions can share a `scheduled_for`, and engine order differs SQLite vs MySQL | A different "last time" in production than in tests |
| **The exercise is never column-limited in an eager load** | A later column returns null forever, suite green | Bitten three times already |
| **A set beyond the target still records** | The target is a note of intent, not a cap | A fourth set refused because three were written down |
| **No 1RM, no volume, no record detection, no trend** | Recording, not coaching | The module becoming a scoreboard |

## 8. What this module may not say

Every gym file is on `CompanionVocabularyTest::sourceFiles()` — writers, read models, controllers,
requests, screens, components. Comments included.

- No `streak`, `completion rate`, `percent`, `points`, `level up`, `cooldown`. `points` and `percent`
  are substring traps: *endpoints* and *percentage* both trip them.
- Sentence case. No exclamation marks. Never congratulating.
- **Never score what was recorded against what was configured.** The session screen's dots and the
  exercise screen's "target 3 × 10" are quoting what the person wrote onto the routine, not
  recommending and not marking. A fraction of a target turns recording into being marked.

The routine editor is on the list for a specific reason worth restating: it is where a person writes
the targets every other gym screen quotes back at them, so it is the most tempting place in the module
to start scoring what they wrote against what they did. The progression screen is the second most
tempting, which is why `ExerciseHistory`'s docblock draws the line hardest there.

`verdict-form.tsx` is on the list too, and is not a training file: it holds the outcome labels and
reason copy for every screen in the app, including the gym session. It replaced three separate copies
of that form.

## 9. Where everything lives

```
database/migrations/
  2026_09_06_122037_create_exercises_table.php
  2026_09_06_130625_create_action_exercises_table.php
  2026_09_06_130625_create_performed_sets_table.php
database/data/exercises.json                  876 rows, committed, Unlicense
database/seeders/ExerciseCatalogueSeeder.php  idempotent on external_id

app/Models/
  Exercise.php                                the catalogue; availableTo()
  ActionExercise.php                          the config site
  PerformedSet.php                            the record site
app/Actions/Training/                         AddRoutineExercise, RemoveRoutineExercise,
                                              ReorderRoutine, RecordSet
app/Services/Training/                        SessionScreen, LastPerformance, ExerciseHistory,
                                              RoutineOrderException
app/Http/Controllers/Training/                Routine, Session, PerformedSet, Exercise,
                                              ExerciseCatalogue, Progression
app/Http/Requests/Training/                   StoreRoutineExercise, StorePerformedSet,
                                              ReorderRoutine
app/Mcp/Tools/                                SearchExercisesTool, AddRoutineExerciseTool,
                                              RemoveRoutineExerciseTool

resources/js/patyourself/training/
  routine-editor.tsx                          WORKFLOWS.gym.config
  gym-record.tsx                              WORKFLOWS.gym.record
  set-grid.tsx                                one row per set, weight/reps/done
resources/js/pages/training/                  session.tsx, exercise.tsx, progression.tsx

tests/Feature/Training/                       including GymWorkflowRegistryTest
```

## 10. What is not done

- **The MCP gym tools are merged and pushed but not yet deployed.** The live connector cannot search
  the catalogue or build a routine. See `docs/DEPLOY-FORGE.md` §14.
- **No exercise images.** The source data has them; `image_path` is null on every imported row.
- **No rep range** — `target_reps` is a single integer. See §3.
- **No set editing or deletion.** `RecordSet` is the only writer and there is no update endpoint, which
  is why a recorded row renders as settled in the set grid. A mistyped set stays mistyped.
- **`ReorderRoutine` has no MCP tool.** The connector can add and remove routine rows but not reorder
  them; reordering is a screen-only act.
- **Nothing verifies the catalogue is seeded** beyond a manual `SELECT COUNT(*)` in the deploy
  checklist. A health check would close the gap that cost weeks of empty searches.
