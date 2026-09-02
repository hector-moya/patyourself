<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\WriteBlobRemarkTool;
use App\Models\CompanionRemark;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The coach writes Blob's remarks. This reverses an earlier ruling knowingly —
 * the words used to be the app's, in config — and the boundary is unchanged:
 * the app makes no model calls, the coach runs outside it and writes through
 * MCP.
 *
 * The app enforces only what it can actually verify. Tone is the coach's
 * responsibility and the tool's description carries the rest of the rules.
 */
class WriteBlobRemarkToolTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<mixed>
     */
    private function payload(TestResponse $response): array
    {
        $content = new \ReflectionMethod($response, 'content');

        /** @var array<int, string> $text */
        $text = $content->invoke($response);

        return json_decode($text[0], true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_it_stores_the_body_verbatim(): void
    {
        $user = User::factory()->create();
        $body = '  Blob has been standing by the window a lot this week.  ';

        $response = PatYourSelfServer::actingAs($user)
            ->tool(WriteBlobRemarkTool::class, ['body' => $body]);

        $response->assertOk();

        $remark = CompanionRemark::firstOrFail();

        $this->assertSame($body, $remark->body);
        $this->assertNull($remark->intention_id);
        $this->assertSame($user->id, $remark->user_id);
        $this->assertSame($body, $this->payload($response)['body']);
    }

    public function test_it_attaches_a_remark_to_a_loop(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();

        PatYourSelfServer::actingAs($user)->tool(WriteBlobRemarkTool::class, [
            'body' => 'Blob has been sitting nearer the door since this one started.',
            'intention_id' => $loop->id,
        ])->assertOk();

        $this->assertSame($loop->id, CompanionRemark::firstOrFail()->intention_id);
    }

    public function test_it_rejects_a_body_over_the_cap(): void
    {
        $user = User::factory()->create();

        PatYourSelfServer::actingAs($user)
            ->tool(WriteBlobRemarkTool::class, ['body' => str_repeat('a', 281)])
            ->assertHasErrors();

        $this->assertSame(0, CompanionRemark::count());
    }

    public function test_it_accepts_a_body_exactly_at_the_cap(): void
    {
        $user = User::factory()->create();

        PatYourSelfServer::actingAs($user)
            ->tool(WriteBlobRemarkTool::class, ['body' => str_repeat('a', 280)])
            ->assertOk();

        $this->assertSame(1, CompanionRemark::count());
    }

    /**
     * `é` is two bytes in UTF-8 and one character. A byte-counting cap would
     * reject 280 of them; the cap counts characters, so it must not.
     */
    public function test_it_accepts_a_multibyte_body_exactly_at_the_cap(): void
    {
        $user = User::factory()->create();

        PatYourSelfServer::actingAs($user)
            ->tool(WriteBlobRemarkTool::class, ['body' => str_repeat('é', 280)])
            ->assertOk();

        $this->assertSame(1, CompanionRemark::count());
    }

    public function test_it_rejects_a_multibyte_body_over_the_cap(): void
    {
        $user = User::factory()->create();

        PatYourSelfServer::actingAs($user)
            ->tool(WriteBlobRemarkTool::class, ['body' => str_repeat('é', 281)])
            ->assertHasErrors();

        $this->assertSame(0, CompanionRemark::count());
    }

    /**
     * Checked at three positions on purpose. A guard written as a substring
     * search with a leading space cannot fire at the start of a string, and the
     * start of a string is where this kind of bug lives.
     *
     * @return array<string, array{string}>
     */
    public static function exclamationProvider(): array
    {
        return [
            'at the start' => ['! Blob is by the window'],
            'in the middle' => ['Blob is by the window! It has been there a while.'],
            'at the end' => ['Blob is by the window!'],
            'alone' => ['!'],
        ];
    }

    #[DataProvider('exclamationProvider')]
    public function test_it_rejects_an_exclamation_mark(string $body): void
    {
        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(WriteBlobRemarkTool::class, ['body' => $body])
            ->assertHasErrors();

        $this->assertSame(0, CompanionRemark::count());
    }

    public function test_it_rejects_a_blank_body(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(WriteBlobRemarkTool::class, ['body' => '   '])
            ->assertHasErrors();

        $this->assertSame(0, CompanionRemark::count());
    }

    public function test_it_refuses_another_users_loop(): void
    {
        $loop = Intention::factory()->for(User::factory()->create())->create();

        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(WriteBlobRemarkTool::class, [
                'body' => 'Blob has been sitting nearer the door.',
                'intention_id' => $loop->id,
            ])
            ->assertHasErrors(['Not found.']);

        $this->assertSame(0, CompanionRemark::count());
    }
}
