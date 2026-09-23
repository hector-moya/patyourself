<?php

namespace Tests\Feature\Companion;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StackWoodTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Two different guards, and a gap neither one covers.
     *
     * `$companion->woodpile` proves `Companion::$attributes` carries the
     * default — delete `'woodpile' => 0` from that array and this line reads
     * null instead of 0. `$user->companion()->value('woodpile')` proves the
     * row is readable and the column is real — comment out the migration's
     * `Schema::table` call and the insert errors before either line runs.
     *
     * NEITHER CATCHES A WRONG COLUMN DEFAULT. `getAttributesForInsert()`
     * returns the model's whole in-memory attribute array with no re-fetch,
     * so `create()` and `firstOrCreate()` always send an explicit
     * `woodpile = 0` on insert — the column's own default clause is never
     * consulted on any write path this application has. MEASURED: the
     * migration's `default(0)` changed to `default(5)`, run, and both
     * assertions stayed green. `xp_spent` carries the identical shape and
     * predates this phase, so this is a property of the pattern, not of
     * this column.
     */
    public function test_a_new_companion_starts_with_nothing_stacked(): void
    {
        $user = User::factory()->create();

        $companion = $user->companion()->create([]);

        $this->assertSame(0, $companion->woodpile);
        $this->assertSame(0, (int) $user->companion()->value('woodpile'));
    }
}
