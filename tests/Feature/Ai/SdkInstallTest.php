<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Tests\TestCase;

class SdkInstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_tables_migrate_and_users_have_conversations(): void
    {
        $user = User::factory()->create();

        $conversation = $user->conversations()->create([
            'id' => (string) Str::uuid(),
            'title' => 'Coach',
        ]);

        $this->assertInstanceOf(Conversation::class, $conversation);
        $this->assertTrue($user->conversations()->whereKey($conversation->id)->exists());
    }
}
