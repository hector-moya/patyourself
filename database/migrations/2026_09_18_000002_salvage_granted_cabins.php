<?php

use App\Actions\SalvageTheCabin;
use Illuminate\Database\Migrations\Migration;

/**
 * Hands every established account its cabin back as a heap of planks.
 *
 * The logic lives in {@see SalvageTheCabin} rather than here, and that is the
 * whole point of the split: what counts as an insight is four derivations, one
 * of which reads JSON out of `intentions.metadata`, and a hand-written SQL
 * copy of that would drift from the resolver the moment either changed — on
 * MySQL in production, against a suite that runs on SQLite. Calling the action
 * means the conversion is tested the way everything else is, with factories
 * and assertions, in `SalvageTheCabinTest`.
 *
 * Safe on a fresh database: there are no users at migration time, so the loop
 * body never runs.
 *
 * There is no `down()`. Nothing is taken back — the heap is Blob's, and this
 * whole feature refuses to remove anything from Blob.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(SalvageTheCabin::class)->handle();
    }
};
