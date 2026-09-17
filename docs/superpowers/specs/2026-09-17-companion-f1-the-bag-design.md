# F1 — The bag

**Status:** designed, not yet planned. Phase F1 of
`2026-09-17-companion-progression-arc-design.md`, which carries the reasoning behind every decision
referenced here. Read it first.

The whole machine, minimal content. When F1 ships, Blob has a name, earns XP from the record, learns
a skill you chose, gathers something from a world the record stocked, and builds a container out of
it — and two people with the same record can have visibly different clearings.

## 1. Content

Deliberately small. Everything here is config, and F2 widens it without touching architecture.

| Thing | Category | Detail |
| --- | --- | --- |
| `reeds` | node | By the water. Visible from the start; unusable without the skill. |
| `deadfall` | node | Under the tree. Visible from the start; unusable without the skill. |
| `gather-fibre` | skill | **20 XP.** Lets Blob harvest reeds. |
| `gather-wood` | skill | **20 XP.** Lets Blob harvest deadfall. |
| `fibre` | material | From reeds. |
| `deadfall` | material | From fallen branches. |
| `basket` | container | **4 fibre.** Capacity +5. |
| `barrow` | container | **4 deadfall.** Capacity +5. |

Base capacity — Blob's hands — is **5**. Each container adds 5, cumulative.

**The fork is real on day one.** 20 XP buys one skill, which leads to one material, which builds one
container. Whichever you chose is standing in your clearing and the other is not. You can buy the
second skill later and build the other too; the record of which came first is permanent.

Building needs no skill — assembling by hand is what hands are for. That keeps F1 at two skills.

## 2. Discovery: clicking the thing you cannot use

This is the mechanic that lets a skill list exist without becoming a checklist, and it is the part
most worth getting right.

Both nodes are **visible in the scene from the start**. Clicking one before you have its skill does
not fail and does not show a lock — Blob walks over and looks at it, and that encounter is what puts
the skill in the list:

```
click the reeds, with no skill
        │
        ▼
"Blob turns the reeds over and puts them down again."
        │
        ▼
the skill list gains:   gather fibre   20 xp
```

The list therefore only ever contains skills whose subject you have already met. It grows and never
shows its own length. **No totals, no "2 of 6", no greyed rows, no prerequisites tree.**

A skill you cannot yet afford stays listed with its price. That is a menu, not a checklist — you
cannot be behind on it because there is no bottom to reach.

## 3. XP — earned is derived, spent is stored

The property worth protecting from the old design is that nothing can silently drift. It survives,
because **only the spending is stored**:

```
balance = max(0, earned − spent)
          ^^^^^^        ^^^^^
          derived       companions.xp_spent
          from the
          record
```

Earned XP is recomputed from the record on every read, exactly as counts are today. Deleting history
lowers `earned` and therefore the balance — but **skills already learned are stored and are never
revoked**, and the clamp stops the balance going negative. The worst case is being unable to buy
until you record more, which takes nothing away. This replaces the `companion_high_water` idea
floated at `CompanionResolver:20`; it is strictly better, because there is no watermark to maintain.

### Rates

| Source | XP |
| --- | --- |
| An outcome recorded — **1st of the day** | 3 |
| — 2nd of the day | 2 |
| — 3rd and every one after | 1 (floor; never zero) |
| A reflection written | 5 |
| A loop's chain corrected | 8 |
| A new strategy version started | 10 |
| An experiment concluded | 15 |

All values live in `config('companion.xp')`. The four insight sources are **outside the taper** —
they are rarer by nature and rate-limiting them would be double-counting.

A `failed` outcome pays exactly what a `completed` one pays. This is not a detail; see the arc doc
§3.

### The taper groups by `logged_at`, not by occasion

`CompanionResolver::logMoments()` already dates by `logged_at` — when the user sat down and told the
truth — rather than by the occasion the log is about. The taper inherits that, and the consequence
is deliberate: **a catch-up session logging seven days at once tapers as one day**, paying
3+2+1+1+1+1+1 = 10 rather than 21.

That is correct, not a bug. The taper rewards showing up, and you showed up once. It also removes any
incentive to batch-fabricate history. The floor of 1 means nothing goes unpaid.

### XP is retroactive over the whole record

An established account arrives rich, and that is right: the app's thesis is that the record is real
and counts, and zeroing it to protect game pacing would be the game wagging the therapy. Being able
to buy both F1 skills immediately is a **reward for having kept the record**.

Pacing survives anyway, because the world is *not* retroactive — see §4.

## 4. The world: stocked by the record, not by the clock

Node stock accrues **only from the moment its skill was learned**. Blob could not see reeds as reeds
before it knew what they were, which is both the in-fiction reason and the mechanical one: it stops a
long-established account from arriving to a clearing holding a thousand units.

- Each outcome recorded adds **1 unit to each unlocked node**.
- Stock is uncapped. The world holds whatever you cannot carry.
- Harvesting moves stock into the bag, limited by remaining capacity.
- **Nothing expires.** Away for two weeks, it is all still there.

When the bag is full, harvesting stops and says so — *"The basket is full. The reeds stay by the
water."* Nothing is destroyed, and there is a reason to build the next container rather than hoard.

The write seam is the existing **`App\Events\ActionLogged`** (dispatched by `App\Actions\LogAction`),
via a new listener. Insight events have no equivalent event today; F1 stocks from outcome logs only,
and whether insights should also stock is an F2 question.

## 5. Storage

Four tables. Follow the existing model convention — the `#[Fillable([...])]` **attribute**, not a
`$fillable` property (see `App\Models\User`, `App\Models\CompanionRemark`).

```
companions
  id, user_id (unique, cascade on delete)
  name          nullable string   — what the user calls Blob; null reads as "Blob"
  xp_spent      unsigned int, default 0
  timestamps

companion_skills
  id, companion_id (cascade)
  name          string
  learned_at    timestamp         — the epoch for this skill's node stocking
  unique (companion_id, name)

companion_items
  id, companion_id (cascade)
  item          string            — category is looked up in config, never duplicated here
  quantity      unsigned int
  unique (companion_id, item)

companion_nodes
  id, companion_id (cascade)
  node          string
  available     unsigned int, default 0
  unique (companion_id, node)
```

`companion_nodes.available` is the one stored value that could in principle be derived. It is stored
anyway, and the exception is deliberate: **the world is a place, not a summary of the record.** It is
mutated by harvesting, and replaying every log to reconstruct it would buy purity at the cost of a
write path that has to agree with a read path.

A `companions` row is created lazily on first need, so nothing has to be backfilled.

## 6. Required change to `CompanionResolver`

`insightMoments()` currently returns `list<CarbonImmutable>` and **discards which kind of insight
each moment was.** The ladder only needs a count, so this never mattered; the XP table in §3 needs
the kind.

Change it to return `list<array{at: CarbonImmutable, kind: string}>`, with `kind` one of
`concluded-experiment`, `started-experiment`, `chain-correction`, `reflection`. The ladder walk keeps
working off `count()` and its behaviour must not change — pin that with a test before touching it.

## 7. The name

A nullable string on `companions`. Null reads as "Blob", so nothing changes until someone renames.

The column is the small part. **The real work is that the authored copy hardcodes the word "Blob"** —
13 ladder messages and 6 tail templates in `config/companion.php`, plus `describe()` in
`companion.tsx`. All of it moves to a `{name}` token, substituted in `CompanionAnnouncement` server
side and at the one client-side call site.

Guard it with a test asserting **no message in `config('companion')` contains the literal string
"Blob"** — otherwise a later rung will quietly reintroduce one and a renamed companion will refer to
itself in the third person.

**Known gap, accepted for F1:** coach-written remarks (`CompanionRemarks`, `WriteBlobRemark`, and the
MCP surfaces that feed them) will still say "Blob" unless the name is passed to the coach. The name
should be exposed wherever the coach reads companion state; if that proves larger than it looks, it
moves to F2 and is recorded as a gap rather than left to be discovered.

## 8. UI — settled

**Settled 17 Sep 2026.** The section previously carried a working assumption — a third panel beside
the room and the record. That is not what ships. **The bag is a button that opens a modal.**

- **The page stays at two panels.** Room and record, exactly as they are today. Nothing moves, and
  the open question in the arc doc §7 about whether the record survives on `/companion` is answered:
  it does, because the bag never competes with it for space.
- **The plinth gains a `Bag 3 / 5` button**, beside Pet, Play and Poke. It carries the capacity
  because that is the bag's one ambient fact — the number that is true whether or not you are
  looking. Everything else in there is static until you act on it.
- **The modal holds the bag**: contents, the build list and the skill list, in the same nine-sliced
  wood as the panels behind it.
- The place bar gains a **balance only** — `48 xp`. No target, no bar, no "next at". A number with a
  ceiling is the checklist coming back in through the window.
- Nodes are clickable inside the existing `CompanionRoom` SVG, beside the foliage layers.
- Phone stacks as it does now, unchanged, because there is no third panel to stack.

**Why a modal rather than a panel.** The bag is a thing you open, not a thing you watch. Nothing in
it changes unless you act — the clearing accrues, the bag does not — so a permanent panel would spend
a third of the screen on state that is static between your own clicks. The room is the thing worth
watching, and it gets the room back.

**The cost, and what pays it.** A panel made one thing legible that a modal hides: clicking a node
you cannot use puts its skill in the list, and behind a modal you would not see that happen. So
**an encounter that reveals a skill opens the bag once, on the spot.** That is not a nag — it is the
response to a click you just made, shown once, at the moment it happens. It turns the modal from a
cost into the beat where discovery lands.

**Implementation note.** Build on `@radix-ui/react-dialog`'s primitives directly, not on
`@/components/ui/dialog`. That wrapper bakes in `bg-background`, `rounded-lg`, `p-6` and a lucide
close button — the notebook's chrome, which is exactly wrong inside the pixel frame. The primitives
give the focus trap, Esc and overlay; the skin is the panels'. Same rule the CSS block already
states: inside these frames the art sets the rules.

Everything new sits inside the pixel panels; the notebook outside them is untouched.

**Art:** reeds and deadfall need sprites. `foliage-grass.png` may stand in for reeds, which would
leave one small asset. Anything more is Pixel Lab work, and `docs/BLOB.md` §7 is clear that it is the
slowest, least predictable part of this feature — so keep F1's art to the minimum that reads.

## 9. Testing

PHP (PHPUnit, feature tests preferred):

- The taper groups by `logged_at` and pays 3/2/1/1; the catch-up case in §3 pays 10 for seven logs.
- Each insight kind pays its configured value.
- A `failed` outcome pays exactly what a `completed` one pays.
- Balance clamps at zero when history is deleted below what was spent, and **learned skills survive**.
- Buying a skill debits `xp_spent` and is rejected when the balance is short.
- `ActionLogged` stocks one unit into each unlocked node, and **none into a node whose skill is not
  learned**.
- Stock accrues only from `learned_at`, so an established account starts at zero.
- Harvesting moves stock to inventory, stops at capacity, and destroys nothing.
- Building consumes exactly its recipe and raises capacity.
- No message in `config('companion')` contains the literal "Blob".
- The existing ladder behaviour is unchanged by the `insightMoments()` refactor.

JS (Vitest):

- Clicking a node without its skill reveals the skill in the list and harvests nothing.
- The skill list passes the existing `never shows what has not happened` guard — no totals, no
  counts, no greyed rows.
- Capacity is shown as held-of-capacity without a completion framing.
- A renamed companion is named everywhere, including the `aria-label` from `describe()`.

Two traps this repo has already been bitten by, both from `docs/BLOB.md` §10: **tests are SQLite and
production is MySQL**, so bind values rather than embedding literals; and `assertDatabaseMissing` on
a column that does not exist is a constant-false predicate that passes forever on SQLite.

## 10. Out of scope for F1

Named so they are not quietly absorbed: tools and the `tool` category, rope and real consumption, the
chest, the lean-to and the shelter arc, wearables migrating into the taxonomy, insight events
stocking the world, and anything Blob *says* about any of this.
