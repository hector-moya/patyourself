<?php

namespace Tests\Feature\Companion;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StackWoodTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_companion_starts_with_nothing_stacked(): void
    {
        $user = User::factory()->create();

        $companion = $user->companion()->create([]);

        $this->assertSame(0, $companion->woodpile);
    }
}
