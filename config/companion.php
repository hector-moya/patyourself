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
    | Renderer
    |--------------------------------------------------------------------------
    |
    | Which implementation draws Blob: `svg` (flat geometry, what ships) or
    | `sprite` (pixel art, not written yet). A flag rather than a rewrite, so
    | the day sprites exist, switching over is a deploy.
    |
    */

    'renderer' => env('COMPANION_RENDERER', 'svg'),

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
    */

    'room' => [
        'day' => ['from' => 7, 'wall' => '#EFE6D6', 'window' => '#B9D5E4'],
        'dusk' => ['from' => 18, 'wall' => '#E7D2BE', 'window' => '#E9A468'],
        'night' => ['from' => 21, 'wall' => '#2F3A40', 'window' => '#1A2530'],
    ],

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
    | The first three stages come from logging so the first week is alive.
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
            'message' => 'Blob is here. New to all this, curious, not remotely cautious. Keep logging and Blob learns — about the world, about itself, about what it might turn into.',
        ],
        [
            'trigger' => 'logs',
            'at' => 3,
            'kind' => 'body',
            'name' => 'legs',
            'message' => 'Blob has legs now. Standing up took most of the day and Blob considers it a fine use of one.',
        ],
        [
            'trigger' => 'logs',
            'at' => 5,
            'kind' => 'item',
            'name' => 'shoes',
            'message' => 'Blob has shoes now. They came before anywhere to go, which Blob does not find odd.',
        ],
        [
            'trigger' => 'insights',
            'at' => 1,
            'kind' => 'ability',
            'name' => 'walk',
            'message' => 'Blob can walk. Slowly, and so far in one direction only.',
        ],
        [
            'trigger' => 'insights',
            'at' => 2,
            'kind' => 'item',
            'name' => 'scarf',
            'message' => 'Blob has a scarf now. It is not cold. Blob likes the scarf.',
        ],
        [
            'trigger' => 'insights',
            'at' => 3,
            'kind' => 'ability',
            'name' => 'read',
            // Reading gives Blob somewhere to keep what it reads. An object
            // arrives with the thing that earned it and never leaves.
            'roomObject' => 'bookshelf',
            'message' => 'Blob can read. What it reads is unclear, but it holds the page the right way up.',
        ],
        [
            'trigger' => 'insights',
            'at' => 4,
            'kind' => 'item',
            'name' => 'hat',
            'message' => 'Blob has a hat now. It wears the hat indoors.',
        ],
        [
            'trigger' => 'insights',
            'at' => 5,
            'kind' => 'ability',
            'name' => 'wave',
            'message' => 'Blob can wave. It mostly waves at things that have not arrived yet.',
        ],
        [
            'trigger' => 'insights',
            'at' => 6,
            'kind' => 'item',
            'name' => 'glasses',
            'message' => 'Blob has glasses now. Nothing about its eyesight has changed.',
        ],
        [
            'trigger' => 'insights',
            'at' => 7,
            'kind' => 'ability',
            'name' => 'jump',
            'message' => 'Blob can jump. Both feet leave the ground, briefly, and it lands where it started.',
        ],
        // The four types are spent. From here an item stage recolours one Blob
        // already owns rather than inventing a fifth thing to collect.
        [
            'trigger' => 'insights',
            'at' => 8,
            'kind' => 'item',
            'name' => 'scarf',
            'variant' => 'coral',
            'message' => 'Blob has another scarf, in coral. The first one is still around, folded somewhere.',
        ],
        [
            'trigger' => 'insights',
            'at' => 9,
            'kind' => 'ability',
            'name' => 'carry',
            'message' => 'Blob can carry something. It has not settled on what.',
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
    |   room_objects  what arrives, walked in order and wrapped
    |   messages    authored lines; {type} and {variant} are substituted
    |
    */

    'tail' => [
        'every' => 3,

        'variants' => ['coral', 'moss', 'slate', 'amber', 'plum', 'rust'],

        'room_every' => 3,

        'room_objects' => ['rug', 'lamp', 'plant', 'stool'],

        // Copy rules as everywhere else: sentence case, no exclamation marks,
        // never congratulating. These describe Blob, not the person reading.
        'messages' => [
            'Blob has another {type}, in {variant}. It keeps the old one, folded somewhere.',
            'A {variant} {type} turned up. Blob has opinions about the colour and is not sharing them.',
            'Blob swapped to the {variant} {type} this morning. No occasion.',
            'There is a {variant} {type} now. Blob wore it immediately and has not mentioned it.',
            'The {variant} {type} arrived. Blob tried it on twice before settling.',
        ],
    ],

];
