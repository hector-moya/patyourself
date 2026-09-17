# Companion progression arc — the umbrella design

**Status:** approved in brainstorming, 17 Sep 2026. Phases F1–F4; only F1 is designed
(`2026-09-17-companion-f1-the-bag-design.md`). F2–F4 are sketched here and must be brainstormed
before they are specced.

This document exists so the reasoning survives. The decisions below were argued out; the ones that
overturn a founding invariant say so and say why.

## 1. What this is

Blob becomes a small point-and-click game — Harvest Moon in a forest clearing. The record feeds it,
the player steers it, and the world changes visibly as a result.

The problem it solves: **nothing about Blob is currently a decision.** The record happens to you, the
ladder is fixed, Blob reflects it. Two people with identical records get identical Blobs, necessarily.
That is a video. What makes something a game is agency over a consequential choice — and that choice
is safe here only because it is about the fiction, never the therapy. **You choose what Blob builds.
You never choose whether to log.**

## 2. The seven decisions

| | Decision |
| --- | --- |
| **Agency** | The player spends, and worlds diverge permanently. Two identical records can produce different Blobs. |
| **Economy** | Two layers. XP from the record buys **skills**; skills unlock **interactions**; interactions yield **materials**; materials build **things**. |
| **Discovery** | The world reveals what is possible; the list keeps it. No totals, no completion, no end state. |
| **Ladder** | Two tracks. The existing ladder keeps **gifting** a body and wearables from the record. XP is the **chosen** track. |
| **Pacing** | The record stocks the world. Clicking harvests it. Nothing expires while you are away. |
| **XP rate** | Per record, tapering within a day. Depth bonuses (reflection, experiment) sit outside the taper. |
| **Capacity** | Capacity caps what Blob holds. The world keeps the overflow. Nothing is ever destroyed. |

### Why two layers rather than one wallet

The two layers pace each other: **the record controls what Blob can do, the world controls how
fast.** A burst of logging cannot skip the world; grinding the world cannot skip the record. One
wallet would collapse that, and materials would become decoration — chopping a log would be an
animation rather than a resource.

### Why the gift track survives

From the CBT reasoning in §3: a **gift** is informational ("your record grew"), a **purchase** is
transactional. Absorbing everything into XP would cost the one reward that is not a trade. The
ladder therefore keeps handing out the body, the wearables and the infinite tail of recolours,
unchosen and unpurchasable, exactly as it does today. Skills and the world are the bought track.

## 3. The CBT reasoning

The goal is to gamify **recording** — good or bad, success or failure — and to reward nothing else.
That is not a compromise forced by the product; it is the correct target.

- **Self-monitoring is an active ingredient, not admin.** Thought records, activity logs and
  behavioural experiments are the therapy, not paperwork preceding it. The reactivity effect —
  measuring a behaviour changes it — means the log *is* the intervention. XP-for-logging aims at the
  right behaviour.
- **Rewarding outcomes would be actively harmful.** Paying for completions builds an incentive to
  hide failures, choose easy actions, and feel worst exactly when the data matters most. A `failed`
  outcome must always pay exactly what a `completed` one pays.
- **No streaks, ever.** Two reasons, and the second is the real one: loss aversion means a broken
  streak predicts abandonment rather than recovery, and **all-or-nothing thinking is a named
  cognitive distortion that CBT explicitly treats.** A streak counter teaches the distortion. This
  also rules out daily energy bars, which are the same loss aversion in a different costume.
- **Informational beats controlling.** Extrinsic reward can erode intrinsic motivation, and the
  mitigation that matters is framing. "Blob has an axe because your record grew" is informational.
  "Log three more times to get the axe" is controlling. Same axe, opposite effect. This is the CBT
  case for the no-checklist rule — it turns out to be defensible on grounds beyond taste.
- **Fixed ratio, never variable.** Slot-machine scheduling is what makes games compulsive. It works,
  and it is the wrong tool in a therapy-adjacent product. Every reward here is predictable and
  transparent.
- **Breadth must not pay better than depth.** Behavioural activation says the value is in showing up,
  and showing up does not scale with how much you showed up for. An uncapped per-record rate would
  mean ten loops earn ten times one loop — so the economy would quietly reward **taking on more
  habits at once**, the most reliable way to fail at habit-building. Hence the daily taper.
- **Effort justification supports the build arc.** People value what they built. A shelter Blob
  assembled from gathered materials is worth more than one handed over at a threshold.

## 4. Invariants: what changes and what holds

Three of the feature's founding rules are affected. Two change, one holds in restated form.

### DEAD — "nothing is stored, so nothing can drift"

`CompanionResolver`'s premise ends here. Choices must persist; a decision you cannot remember is not
a decision. Replaced by a narrower rule that keeps most of the value:

> **Store only what cannot be derived.**

Choices (skills bought, the name), inventory and world stock are stored. **Counts, earned XP and the
gift ladder stay derived**, so the parts that could drift still cannot. See F1 §3 for how the XP
wallet keeps this property while still being spendable.

### REWRITTEN — "never show what has not happened"

Spending XP on a skill means seeing skills you do not have. The rule cannot survive as written — but
what made it right was never *unowned things being visible*. It was **a fixed total with your
distance from it.** "3 of 10 rungs" is a checklist you can be behind on; "Blob could learn to twist
fibre" is a menu you cannot.

> **Never show a total, a completion percentage, or an end state. Show what is choosable now.**

The existing guard in `companion.test.tsx` (`never shows what has not happened`, matching
`/locked|next up|to unlock|remaining|streak|\d+ of \d+/`) is **kept**, and every new surface must
pass it. A skill list with prices passes. A skill list reading "2 of 6 known" does not.

### HOLDS, restated — "nothing regresses"

Materials **transform**; they are never taken. Spending rope on a hut is not losing rope, it is the
hut having rope in it. Consumables therefore do not break the rule, and the line is held hard: no
decay, no expiry, no upkeep, nothing removed from Blob for inactivity, ever.

## 5. The item taxonomy

The four-type cap in `config('companion.item_types')` was always about **clutter on a 64×64 sprite**,
never about how many things may exist in the world. It moves down to the category it was really
describing.

| Category | Cap | Example | Where it lives |
| --- | --- | --- | --- |
| `wearable` | **4 types, forever** (then recolours) | shoes, scarf, hat, glasses | on Blob |
| `tool` | none | axe, handsaw | in the bag; enables an interaction |
| `material` | none | fibre, deadfall, logs, planks | in the bag; consumed by recipes |
| `consumable` | none | rope | in the bag; consumed by recipes |
| `container` | none | bag, backpack, chest | raises capacity |
| `structure` | none | lean-to, hut | in the world, permanent |

The wearable cap and its recolour tail are unchanged and still guarded by `CompanionLadderTest`.

## 6. Phases

Strict dependency order. Each gets its own brainstorm → spec → plan → build cycle. Only F1 is
designed.

**F1 — The bag.** The whole machine, minimal content. Storage foundation, taxonomy, XP wallet and
taper, the skill-buying surface, world stocking, harvesting. Two gather skills, two materials and
**two containers, one per material**, so the first 20 XP you spend decides which one is standing in
your clearing — the fork is exercised on day one rather than deferred to F2. **Blob's name ships
here**, since it needs the same row. Designed in `2026-09-17-companion-f1-the-bag-design.md`.

**F2 — The tools.** The `tool` category in earnest: axe and handsaw, chopping and sawing, logs and
planks, rope. Pure content on F1's rails; no new architecture. This is where the taxonomy's unused
categories get used.

**F3 — The shelter.** `lean-to` and `hut` as **built** scenes rather than threshold-triggered ones.
The arc is `forest → lean-to → hut → cabin`, and the cabin is the destination that already exists.
**The threshold rule from E1 still binds: `cabin` sits at `insights: 5` and never moves; lean-to and
hut are inserted below it.** The one phase with real art risk — new backdrops through Pixel Lab,
which `docs/BLOB.md` §7 shows is the slowest and least predictable part of this feature.

**F4 — Depth.** Consumption proper, the chest, more skills, wearables migrating into the taxonomy,
and whatever F1–F3 taught us. Deliberately vague: designing it now would be guessing.

## 7. Open questions, deferred on purpose

- ~~**Where the surfaces live.**~~ **Settled 17 Sep 2026**, see F1 §8: the page stays at two panels,
  the bag is a button in the plinth that opens a modal, and the XP balance sits in the place bar.
- **What Blob says about any of this.** The remark system (`CompanionRemarks`) is the natural voice
  for "Blob has been looking at the reeds", but nothing here depends on it and F1 does not touch it.
- ~~**Whether the record panel stays on `/companion`.**~~ **Settled 17 Sep 2026:** it stays, and is
  untouched. The question only existed because a third panel would have crowded it; the bag being a
  modal means nothing ever competes with the record for the page.

## 8. What would make this wrong

Recorded so a later reader can check rather than re-derive:

- If a surface ever shows a completion total, the rewritten rule in §4 has been broken and the
  feature is a checklist again.
- If XP ever varies by whether an outcome was a success, §3 has been broken and the app is paying
  people to hide failures.
- If anything is ever removed from Blob for inactivity, §4's third rule has been broken.
- If the fastest route to progression is creating more loops, the taper in §3 is mistuned.
