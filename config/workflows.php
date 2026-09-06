<?php

use App\Models\ActionExercise;
use App\Models\PerformedSet;

return [

    /*
    |--------------------------------------------------------------------------
    | The workflow registry
    |--------------------------------------------------------------------------
    |
    | Every workflow this codebase knows, keyed by the value stored in
    | intentions.workflow. A loop names one to reach a recording surface; a loop
    | with no workflow — every loop today, and the ordinary case forever —
    | reaches nothing here and records its outcome through the plain screen.
    |
    | Each entry says what the workflow attaches at the two extension sites:
    |
    |   config  a model keyed to `actions`     — what an occasion is meant to contain
    |   record  a model keyed to `occurrences` — what it actually contained
    |
    | Both are optional. A workflow that attaches nothing at a site is not a
    | special case; it is an empty site.
    |
    | Adding a name here is what makes it choosable — a workflow is spelled by
    | this file, never typed by a user, which is the whole difference between
    | this and the free-form tag it replaced.
    |
    | `gym` is the first module plugged into these two extension sites: its
    | routine (config) is keyed to `actions`, its performed sets (record) are
    | keyed to `occurrences`.
    |
    */

    'registry' => [
        'gym' => [
            'label' => 'Gym',
            'config' => ActionExercise::class,
            'record' => PerformedSet::class,
        ],
    ],

];
