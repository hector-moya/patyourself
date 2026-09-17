<?php

/**
 * Blob — the app's only reward surface.
 *
 * Blob tracks the work, not the user. It grows from the record of an inquiry
 * being kept: outcomes logged (whatever they were) and insight events. A
 * `failed` outcome advances it exactly as far as a `completed` one, because the
 * behaviour being rewarded is honest logging, not adherence.
 *
 * Nothing here decays, regresses or expires. There is no "next" entry exposed
 * anywhere in the feature: showing what is not yet unlocked would turn Blob into
 * a checklist, which is the one thing it must not be.
 *
 * ADDING A STAGE IS A CONFIG EDIT. Append an entry to `ladder` and nothing else
 * changes — the resolver walks this list, the screen renders what it returns.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Item types
    |--------------------------------------------------------------------------
    |
    | The things Blob wears, capped at four distinct types forever. Past the
    | fourth, an item stage re-uses a type it already has and names a `variant`
    | instead — a recolour, not a new thing to collect. The cap is data, so
    | CompanionLadderTest asserts the ladder never introduces a fifth type.
    |
    */

    'item_types' => ['shoes', 'scarf', 'hat', 'glasses'],

    /*
    |--------------------------------------------------------------------------
    | Item display nouns
    |--------------------------------------------------------------------------
    |
    | What a type is called in a sentence. `shoes` and `glasses` are plural
    | nouns — "another shoes" and "a amber glasses" are not English — so the
    | tail's copy substitutes this noun for `{type}` instead of the raw type
    | name. `name` (what the resolver and renderer key everything by) is
    | untouched; only the words shown to a person change. Types not listed
    | here map to themselves.
    |
    */

    'item_display_names' => [
        'shoes' => 'pair of shoes',
        'glasses' => 'pair of glasses',
    ],

    /*
    |--------------------------------------------------------------------------
    | Renderer
    |--------------------------------------------------------------------------
    |
    | Which implementation draws Blob: `sprite` (pixel art, what ships) or
    | `svg` (flat geometry, the fallback). A flag rather than a rewrite, so
    | reverting to the old drawing is a deploy, not a rollback of code.
    |
    */

    'renderer' => env('COMPANION_RENDERER', 'sprite'),

    /*
    |--------------------------------------------------------------------------
    | The room
    |--------------------------------------------------------------------------
    |
    | Blob's interior, tinted by the part of the day. Read from the CLIENT
    | clock — the room should look different at breakfast and at dinner, and
    | which of those it is depends on where the person is sitting, not on where
    | the server is.
    |
    | `from` is the local hour each part of the day starts at, and they are read
    | in order, wrapping past midnight. No weather, no seasons, no API: this is
    | the cheapest liveness in the whole feature and it stays that way.
    |
    | `light` and `dim` feed a later overlay drawn over the whole scene,
    | including Blob itself. `dim` is how strongly `light` washes the scene —
    | zero at midday, when the light needs no help.
    |
    */

    'room' => [
        'sunrise' => ['from' => 5, 'wall' => '#F2E0D0', 'window' => '#F0B98A', 'light' => '#F4A15C', 'dim' => 0.18],
        'day' => ['from' => 8, 'wall' => '#EFE6D6', 'window' => '#B9D5E4', 'light' => '#FFFFFF', 'dim' => 0],
        'dusk' => ['from' => 18, 'wall' => '#E7D2BE', 'window' => '#E9A468', 'light' => '#E2762F', 'dim' => 0.22],
        // `asleep` marks the part Blob sleeps through. It sits here, beside the
        // hours, rather than being decided in the drawing: which part of the day
        // a creature sleeps through is an authored decision. Nothing in the app
        // exposes it, so it is not a setting.
        'night' => ['from' => 21, 'wall' => '#2F3A40', 'window' => '#1A2530', 'light' => '#2B3F6B', 'dim' => 0.42, 'asleep' => true],
    ],

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

    /*
    |--------------------------------------------------------------------------
    | The scene override
    |--------------------------------------------------------------------------
    |
    | Draws the scene named here instead of the one the record derives, on the
    | same env-flag precedent as `renderer` above.
    |
    | A DEVELOPMENT AFFORDANCE, NEVER A SETTING. A scene the reader can choose
    | is a setting rather than a consequence of the record, and every other
    | part of this feature refuses to be that — so this is read from the
    | environment and has no surface anywhere in the app. It exists because the
    | cabin's threshold sits below an established record, which leaves the
    | forest unreachable on a deep record without it.
    |
    | Empty is absent: `COMPANION_SCENE=` reads back as '' and the record
    | decides, exactly as when the variable is unset. A name no scene knows is
    | handed to the client as it stands and falls back there — see `sceneFor`
    | in `scenes.ts`, which is the registry of what can actually be drawn.
    |
    */

    'scene_override' => env('COMPANION_SCENE'),

    /*
    |--------------------------------------------------------------------------
    | The unlock ladder
    |--------------------------------------------------------------------------
    |
    | Ordered, and walked in order: the first unsatisfied entry ends the walk.
    | Each entry is:
    |
    |   trigger     'logs' (outcomes recorded) or 'insights' (see below)
    |   at          how many of that trigger this entry needs
    |   kind        'body' (Blob itself), 'item' (worn) or 'ability' (done)
    |   name        the body part, item type or ability name
    |   variant     items only — a colour, once the four types are spent
    |   roomObject  optional; something this unlock puts in Blob's room, forever
    |   message     the app's own voice, relayed verbatim by the coach
    |
    | An insight event is an existing record, never a judgement: an experiment
    | concluded (any verdict), a new strategy version started, a loop's
    | cue/craving/response/reward corrected, or a reflection written.
    |
    | The first four stages come from logging so the first week is alive.
    | Everything after alternates ability -> item, because the abilities are the
    | more interesting track and the items run out.
    |
    | Copy rules: sentence case, one or two sentences, no exclamation marks,
    | never congratulating, no second person keeping score. Say what Blob can now
    | do — never how well the user is performing.
    |
    */

    'ladder' => [
        [
            'trigger' => 'logs',
            'at' => 1,
            'kind' => 'body',
            'name' => 'blob',
            'message' => '{name} is here. New to all this, curious, not remotely cautious. Keep logging and {name} learns — about the world, about itself, about what it might turn into.',
        ],
        [
            'trigger' => 'logs',
            'at' => 3,
            'kind' => 'body',
            'name' => 'legs',
            'message' => '{name} has legs now. Standing up took most of the day and {name} considers it a fine use of one.',
        ],
        [
            'trigger' => 'logs',
            'at' => 4,
            'kind' => 'body',
            'name' => 'arms',
            'message' => '{name} has arms now. It has not decided what they are for.',
        ],
        [
            'trigger' => 'logs',
            'at' => 5,
            'kind' => 'item',
            'name' => 'shoes',
            'message' => '{name} has shoes now. They came before anywhere to go, which {name} does not find odd.',
        ],
        [
            'trigger' => 'insights',
            'at' => 1,
            'kind' => 'ability',
            'name' => 'walk',
            'message' => '{name} can walk. Slowly, and so far in one direction only.',
        ],
        [
            'trigger' => 'insights',
            'at' => 2,
            'kind' => 'item',
            'name' => 'scarf',
            'message' => '{name} has a scarf now. It is not cold. {name} likes the scarf.',
        ],
        [
            'trigger' => 'insights',
            'at' => 3,
            'kind' => 'ability',
            'name' => 'read',
            // Reading gives Blob somewhere to keep what it reads. An object is
            // earned once and stays earned; the cabin is where it is drawn.
            'roomObject' => 'bookshelf',
            'message' => '{name} can read. What it reads is unclear, but it holds the page the right way up.',
        ],
        [
            'trigger' => 'insights',
            'at' => 4,
            'kind' => 'item',
            'name' => 'hat',
            'message' => '{name} has a hat now. It wears the hat indoors.',
        ],
        [
            'trigger' => 'insights',
            'at' => 5,
            'kind' => 'ability',
            'name' => 'wave',
            // A rug to wave from. An object is earned once and stays earned;
            // the cabin is where it is drawn.
            'roomObject' => 'rug',
            'message' => '{name} can wave. It mostly waves at things that have not arrived yet.',
        ],
        [
            'trigger' => 'insights',
            'at' => 6,
            'kind' => 'item',
            'name' => 'glasses',
            'message' => '{name} has glasses now. Nothing about its eyesight has changed.',
        ],
        [
            'trigger' => 'insights',
            'at' => 7,
            'kind' => 'ability',
            'name' => 'jump',
            // Somewhere to land the jump under. An object is earned once and
            // stays earned; the cabin is where it is drawn.
            'roomObject' => 'lamp',
            'message' => '{name} can jump. Both feet leave the ground, briefly, and it lands where it started.',
        ],
        // The four types are spent. From here an item stage recolours one Blob
        // already owns rather than inventing a fifth thing to collect.
        [
            'trigger' => 'insights',
            'at' => 8,
            'kind' => 'item',
            'name' => 'scarf',
            'variant' => 'coral',
            'message' => '{name} has another scarf, in coral. The first one is still around, folded somewhere.',
        ],
        [
            'trigger' => 'insights',
            'at' => 9,
            'kind' => 'ability',
            'name' => 'carry',
            // Something to carry toward. An object is earned once and stays
            // earned; the cabin is where it is drawn.
            'roomObject' => 'plant',
            'message' => '{name} can carry something. It has not settled on what.',
        ],
    ],

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
    |   room_objects  what arrives, walked in order and wrapped — three of
    |               these four (`rug`, `lamp`, `plant`) are also handed out by
    |               authored rungs, so most tail room-object hand-outs are a
    |               REPEAT of something Blob already has. That is a known,
    |               ruled-on overlap, not a bug: `CompanionState::roomObjects()`
    |               dedupes before the view ever sees a name twice, so a
    |               repeated hand-out is an ordinary recolour rung with no
    |               bonus object, never an empty one. See
    |               CompanionLadderTest::test_the_tail_object_pool_overlaps_the_authored_ladder_on_purpose.
    |   messages    authored lines; {type} and {variant} are substituted.
    |               {type} is rendered through `item_display_names` above, not
    |               the raw type, so a plural type ("shoes", "glasses") still
    |               reads as English. Every variant here MUST be a colour the
    |               renderer's PALETTE knows (blob-renderer.tsx) and MUST NOT
    |               equal an item's own default colour — CompanionTailPaletteTest
    |               guards both. `slate` and `rust` are deliberately absent:
    |               they are `shoes`/`hat`'s and `scarf`'s own default colours,
    |               so a rung naming them would announce a recolour that never
    |               shows up on screen. The palette is 5 long against 4 item
    |               types on purpose (coprime, so a full cycle is 20 rungs
    |               before any type/colour pairing repeats) rather than a
    |               length that shares a factor with 4, which would lock each
    |               type to a single colour forever.
    |
    |               `variants` and `messages` are indexed by the SAME `$index`
    |               in CompanionResolver::tailUnlocks(), so they cycle in
    |               lockstep — if their counts shared a factor, one variant
    |               would be permanently welded to one template (coral always
    |               template 0, forever). 5 variants and 6 messages are
    |               coprime, so a (variant, message) pairing does not repeat
    |               for 30 rungs.
    |
    */

    'tail' => [
        'every' => 3,

        'variants' => ['coral', 'moss', 'amber', 'plum', 'sand'],

        'room_every' => 3,

        'room_objects' => ['rug', 'lamp', 'plant', 'stool'],

        // Copy rules as everywhere else: sentence case, no exclamation marks,
        // never congratulating. These describe Blob, not the person reading.
        // No template places an indefinite article ("a"/"an") directly before
        // {variant}: colour names include vowel-initial ones (amber) and
        // consonant-initial ones (coral, moss, plum, sand), and picking the
        // right article per name is exactly the bug this avoids by
        // construction rather than by special-casing vowels. "the {variant}"
        // and "in {variant}" both sidestep the agreement question entirely.
        'messages' => [
            '{name} has another {type}, in {variant}. It keeps the old one, folded somewhere.',
            'A {type} turned up in {variant}. {name} has opinions about the colour and is not sharing them.',
            '{name} swapped to the {variant} {type} this morning. No occasion.',
            'There is a {type} now, in {variant}. {name} wore it immediately and has not mentioned it.',
            'The {variant} {type} arrived. {name} tried it on twice before settling.',
            'Something changed: the {type} is {variant} now. {name} has not commented.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | XP
    |--------------------------------------------------------------------------
    |
    | What the record pays. The other track entirely from the ladder above: the
    | ladder GIFTS a body and wearables from the record, unchosen and
    | unpurchasable, and always will. XP is what the player chooses to spend.
    |
    | Earned XP is recomputed from the record on every read; only the spending
    | is stored, in `companions.xp_spent`. See F1 §3.
    |
    | A `failed` outcome pays exactly what a `completed` one pays, and a
    | `skipped` one pays the same again. Paying for completions would build an
    | incentive to hide failures, choose easy actions, and feel worst exactly
    | when the data matters most. NOTHING THAT READS THIS MAY EVER BRANCH ON AN
    | OUTCOME.
    |
    | FIXED RATIO, NEVER VARIABLE. Slot-machine scheduling is what makes games
    | compulsive; it works, and it is the wrong tool in a therapy-adjacent
    | product. Every reward here is predictable and transparent.
    |
    */

    'xp' => [

        /*
        | The nth outcome recorded in one day, grouped by `logged_at` — when the
        | user sat down and told the truth — rather than by the occasion it is
        | about. A catch-up session logging seven days at once therefore tapers
        | as ONE day and pays 10, not 21. That is correct: the taper rewards
        | showing up, and you showed up once. It also removes any incentive to
        | batch-fabricate history.
        |
        | The taper exists because breadth must not pay better than depth. An
        | uncapped per-record rate would mean ten loops earn ten times one loop,
        | so the economy would quietly reward taking on more habits at once —
        | the most reliable way to fail at habit-building.
        |
        | The last value is the FLOOR and repeats forever. Nothing recorded ever
        | goes unpaid: the taper is there so breadth does not out-earn depth,
        | not so that a tenth log is worthless.
        */
        'outcome' => [3, 2, 1],

        /*
        | The four insight sources, keyed by CompanionResolver's INSIGHT_*
        | constants. OUTSIDE the taper: they are rarer by nature, and
        | rate-limiting them would be double-counting.
        */
        'insight' => [
            'concluded-experiment' => 15,
            'started-experiment' => 10,
            'chain-correction' => 8,
            'reflection' => 5,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Skills
    |--------------------------------------------------------------------------
    |
    | What XP buys, and the one consequential choice in the whole feature: two
    | identical records can produce different Blobs, permanently, because of
    | what was bought first.
    |
    | A skill unlocks an interaction with one node; the node yields a material;
    | the material builds a thing. Two layers, and they pace each other — the
    | record controls what Blob CAN do, the world controls how fast. One wallet
    | would collapse that and materials would become decoration.
    |
    | A skill is shown to the user only once Blob has MET its node, so THIS MAP
    | IS NEVER RENDERED WHOLE AND ITS LENGTH IS NEVER SHOWN. A list that grows
    | as you meet things is a menu; a list of everything that exists, with most
    | of it greyed, is a checklist.
    |
    | `label` is what the row says, `price` is what it costs, `node` is what it
    | unlocks. F1 ships two; F2 widens this without touching any of the code
    | that reads it.
    |
    | Sawing is a recipe rather than a node, and a recipe never gates on a
    | skill — hands have never needed permission, they need the right thing in
    | them. So F2 adds one skill, not two. Pacing has never come from XP
    | breadth; it comes from the world, which now accrues across three nodes.
    |
    */

    'skills' => [
        'gather-fibre' => ['price' => 20, 'node' => 'reeds', 'label' => 'gather fibre'],
        'gather-wood' => ['price' => 20, 'node' => 'deadfall', 'label' => 'gather wood'],
        'chop-wood' => ['price' => 20, 'node' => 'trunk', 'label' => 'chop wood'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Nodes
    |--------------------------------------------------------------------------
    |
    | What stands in the clearing. Both are VISIBLE FROM THE START and unusable
    | without their skill — clicking one before you have it does not fail and
    | does not show a lock. Blob turns it over and puts it down again, and that
    | encounter is what puts the skill in the list. (F1 §2)
    |
    | Each outcome recorded adds one unit to each node whose skill is learned,
    | and only from the moment it was learned. Stock is uncapped and nothing
    | expires: away for two weeks, it is all still there.
    |
    | `deadfall` is deliberately both a node and a material. They live in
    | different maps and different tables, and calling the fallen branches one
    | thing and what you carry away from them another would be two words for
    | one object.
    |
    | Copy rules as everywhere else. `met` is what Blob does with a node it
    | cannot use yet: it describes Blob, states no requirement, and never hints
    | at a price — the price appears in the bag, which the encounter opens.
    | `full` is what happens when there is nowhere to put what it picked up;
    | it names where the thing stays, because nothing is ever destroyed.
    |
    | `blunt` is the fifth line and the only one a node without a `tool` key
    | does not need: it is what Blob does at a node it has the record's
    | permission for and not the thing in hand.
    |
    */

    'nodes' => [
        'reeds' => [
            'skill' => 'gather-fibre',
            'yields' => 'fibre',
            'label' => 'the reeds',
            'met' => '{name} turns the reeds over and puts them down again.',
            'took' => '{name} comes back with {count} fibre.',
            'empty' => '{name} checks the reeds. Nothing has grown back yet.',
            'full' => 'There is nowhere to put them. The reeds stay by the water.',
        ],
        'deadfall' => [
            'skill' => 'gather-wood',
            'yields' => 'deadfall',
            'label' => 'the fallen branches',
            'met' => '{name} pushes at a fallen branch and leaves it where it is.',
            'took' => '{name} drags back {count} deadfall.',
            'empty' => '{name} checks under the tree. Nothing has come down since.',
            'full' => 'There is nowhere to put it. The branches stay under the tree.',
        ],
        'trunk' => [
            'skill' => 'chop-wood',
            // The second gate. A node may ask for a thing in hand as well as
            // for the record's permission, and this is the one place in the
            // feature where the two economic layers meet on one gesture.
            'tool' => 'axe',
            'yields' => 'timber',
            'label' => 'the fallen trunk',
            'met' => '{name} climbs onto the fallen trunk and sits there a while.',
            // Said when the skill is known and the tool is not held. It
            // describes what {name} is carrying and never names what to go and
            // build: the pile of deadfall and the recipe already in the bag
            // are the inference, and the app doing the saying is the one thing
            // this feature refuses.
            'blunt' => '{name} looks at the trunk. Nothing it is carrying will bite into it.',
            'took' => '{name} drags back {count} timber.',
            'empty' => '{name} checks the trunk. There is nothing loose on it.',
            'full' => 'There is nowhere to put it. The timber stays by the trunk.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | The bag
    |--------------------------------------------------------------------------
    |
    | Everything Blob can hold or build, keyed by the name stored in
    | `companion_items.item`. THE CATEGORY LIVES HERE AND NOWHERE ELSE — a row
    | that carried its own category would be a second copy of this, and the two
    | would eventually disagree about what a thing is.
    |
    | This is not `item_types`, which is the four WEARABLES and stays capped
    | forever. Nothing here goes on Blob, and nothing here may reuse a
    | wearable's name — CompanionContentTest guards that.
    |
    | Materials TRANSFORM; they are never taken. Spending fibre on a basket is
    | not losing fibre, it is the basket having fibre in it. That is what keeps
    | consumption inside the "nothing regresses" rule.
    |
    | Building needs no skill: assembling by hand is what hands are for, and it
    | keeps F1 at two skills.
    |
    | `timber`, not `logs`. `logs` already means something two hundred lines up
    | — `'trigger' => 'logs'`, and `logCount` and `logMoments()` beyond this
    | file. A material by that name would sit here meaning something else
    | entirely, and every future grep for either would find both.
    |
    | A `tool` is on the belt: it is not in `capacity.carried`, so it never
    | occupies the room it lets you use. A tool is permanent, and a permanent
    | thing in a slot would be a permanent tax — gaining the axe would shrink
    | the bag forever, which is regression in everything but name.
    |
    */

    'bag' => [
        'fibre' => ['category' => 'material', 'label' => 'fibre'],
        'deadfall' => ['category' => 'material', 'label' => 'deadfall'],
        'timber' => ['category' => 'material', 'label' => 'timber'],

        'rope' => [
            'category' => 'consumable',
            'label' => 'rope',
            'recipe' => ['fibre' => 3],
        ],
        'axe' => [
            'category' => 'tool',
            'label' => 'axe',
            'recipe' => ['deadfall' => 2, 'rope' => 1],
        ],
        'handsaw' => [
            'category' => 'tool',
            'label' => 'handsaw',
            'recipe' => ['timber' => 2, 'rope' => 1],
        ],
        'planks' => [
            'category' => 'material',
            'label' => 'planks',
            // The recipe gate. Sawing is hands-work, so it asks for nothing
            // the record has to grant — only for the thing that does the
            // sawing.
            'tool' => 'handsaw',
            'recipe' => ['timber' => 1],
            'makes' => 3,
        ],
        'crate' => [
            'category' => 'container',
            'label' => 'crate',
            'recipe' => ['planks' => 4],
            'capacity' => 5,
        ],

        'basket' => [
            'category' => 'container',
            'label' => 'basket',
            'recipe' => ['fibre' => 4],
            'capacity' => 5,
        ],
        'barrow' => [
            'category' => 'container',
            'label' => 'barrow',
            'recipe' => ['deadfall' => 4],
            'capacity' => 5,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Capacity
    |--------------------------------------------------------------------------
    |
    | What Blob's hands hold, and which categories take up that room. A
    | container does not occupy the space it creates; a tool, when F2 adds one,
    | is on the belt.
    |
    | Capacity CAPS WHAT BLOB HOLDS, never what the world has. When the bag is
    | full, harvesting stops and says so — the remainder stays standing where
    | it was, nothing is destroyed, and there is a reason to build the next
    | container rather than hoard.
    |
    */

    'capacity' => [
        'base' => 5,
        'carried' => ['material', 'consumable'],
    ],

];
