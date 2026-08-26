<?php

namespace App\Events;

use App\Models\Occurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An occasion's moment has arrived and the trigger engine has claimed it. The
 * event fires exactly once per occasion — the claim is a guarded update on
 * `fired_at`, so a repeated or overlapping run raises nothing.
 *
 * The action is reachable through the occasion. It is deliberately not the
 * subject: a standing prescription does not fire, its occasions do.
 */
class OccurrenceFired
{
    use Dispatchable;

    public function __construct(public readonly Occurrence $occurrence) {}
}
