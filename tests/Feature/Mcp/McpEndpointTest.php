<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The MCP endpoint's front door: OAuth discovery metadata for claude.ai's
 * dynamic client registration, and Passport-guarded access to /mcp itself.
 */
class McpEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Hyphenated words in the prompt prose that are English, not tool names.
     * Adding a hyphenated word to a prompt means adding it here — the guard
     * below deliberately errs toward failing loudly.
     *
     * @var array<int, string>
     */
    private const PROSE_COMPOUNDS = ['check-in'];

    public function test_publishes_oauth_discovery_metadata(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonStructure([
                'issuer',
                'authorization_endpoint',
                'token_endpoint',
                'registration_endpoint',
            ]);
    }

    public function test_publishes_protected_resource_metadata(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource')->assertOk();
    }

    public function test_guests_cannot_reach_the_mcp_endpoint(): void
    {
        $this->postJson('/mcp', $this->initializePayload())->assertUnauthorized();
    }

    public function test_an_authenticated_user_can_initialize(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use']);

        $response = $this->postJson('/mcp', $this->initializePayload(), [
            'Accept' => 'application/json, text/event-stream',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('PatYourSelf', $response->getContent());
    }

    public function test_a_token_without_the_mcp_use_scope_is_rejected(): void
    {
        Passport::actingAs(User::factory()->create(), []);

        $response = $this->postJson('/mcp', $this->initializePayload(), [
            'Accept' => 'application/json, text/event-stream',
        ]);

        $response->assertForbidden();
    }

    /**
     * Exact names, not substrings: `list-loops-tool` contains `list-loops`, so a
     * containment check silently accepts the wrong name and Claude's calls 404.
     */
    public function test_advertises_every_tool_under_its_documented_name(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use']);

        $response = $this->toolsList();

        $response->assertOk();

        $this->assertSame(
            ['list-loops', 'get-loop', 'today-actions', 'pending-outcomes', 'log-outcome', 'loop-outcomes', 'loop-progress', 'create-loop', 'start-experiment', 'conclude-experiment', 'add-action', 'update-action', 'remove-action', 'update-loop', 'log-note', 'write-reflection', 'write-blob-remark'],
            array_column($response->json('result.tools'), 'name'),
        );
    }

    /**
     * Every registered tool has to arrive on the first page.
     *
     * Laravel MCP paginates `tools/list` at 15 by default. This server passed 15
     * when write-reflection was added, so the 16th tool landed on page two and
     * was invisible to any client that does not follow `nextCursor` — a silent
     * failure, because the server stays healthy and the tool simply never gets
     * offered. Asserted against the registered count rather than a literal, so
     * it keeps holding as tools are added.
     */
    public function test_every_registered_tool_arrives_on_the_first_page(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use']);

        $response = $this->toolsList();

        $response->assertOk();

        $registered = (new \ReflectionClass(PatYourSelfServer::class))
            ->getDefaultProperties()['tools'];

        $this->assertCount(count($registered), $response->json('result.tools'));
        $this->assertNull($response->json('result.nextCursor'));
    }

    /**
     * The server's #[Instructions] tell Claude which tools to call by name. If a
     * name drifts from that prose, Claude calls a tool that does not exist.
     */
    public function test_every_advertised_tool_name_appears_in_the_server_instructions(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use']);

        $instructions = $this->postJson('/mcp', $this->initializePayload(), [
            'Accept' => 'application/json, text/event-stream',
        ])->json('result.instructions');

        foreach (array_column($this->toolsList()->json('result.tools'), 'name') as $name) {
            $this->assertStringContainsString($name, $instructions);
        }
    }

    /**
     * Same trap as tools/list: Laravel MCP paginates at 15 by default and this
     * server raises it to 50. Asserted against the registered count rather than
     * a literal so it keeps holding as prompts are added.
     */
    public function test_every_registered_prompt_arrives_on_the_first_page(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use']);

        $response = $this->promptsList();

        $response->assertOk();

        $registered = (new \ReflectionClass(PatYourSelfServer::class))
            ->getDefaultProperties()['prompts'];

        $this->assertCount(count($registered), $response->json('result.prompts'));
        $this->assertNull($response->json('result.nextCursor'));
    }

    /**
     * A prompt seeds a conversation: the guidance is the assistant's, and the
     * opener has to be the user's, or the coach reads its own instructions back
     * as though the user had asked for them.
     */
    public function test_every_prompt_opens_with_guidance_and_ends_with_the_user_speaking(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use']);

        foreach (array_column($this->promptsList()->json('result.prompts'), 'name') as $name) {
            $this->assertSame(
                ['assistant', 'user'],
                array_column($this->promptsGetResult($name)['messages'], 'role'),
                "Prompt [{$name}] does not open with guidance and end with the user speaking.",
            );
        }
    }

    /**
     * A prompt that names a tool the server does not register sends Claude after
     * something that 404s — the mirror image of the instructions guard above.
     * Scanned out of the rendered messages rather than checked against a literal
     * list, so a typo in the prose fails here rather than shipping.
     */
    public function test_every_tool_a_prompt_names_is_a_registered_tool(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use']);

        $registered = array_column($this->toolsList()->json('result.tools'), 'name');

        foreach (array_column($this->promptsList()->json('result.prompts'), 'name') as $name) {
            $rendered = (string) json_encode($this->promptsGetResult($name)['messages']);

            preg_match_all('/\b[a-z]+(?:-[a-z]+)+\b/', $rendered, $matches);

            $named = array_values(array_diff(array_unique($matches[0]), self::PROSE_COMPOUNDS));

            $this->assertNotEmpty($named, "Prompt [{$name}] names no tools at all.");

            foreach ($named as $tool) {
                $this->assertContains(
                    $tool,
                    $registered,
                    "Prompt [{$name}] names [{$tool}], which is not a registered tool.",
                );
            }
        }
    }

    private function toolsList(): TestResponse
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ], ['Accept' => 'application/json, text/event-stream']);
    }

    private function promptsList(): TestResponse
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'prompts/list',
            'params' => [],
        ], ['Accept' => 'application/json, text/event-stream']);
    }

    /**
     * prompts/get arrives as Server-Sent Events, not JSON.
     *
     * A prompt's handle() returns an array of messages, which the server treats
     * as an iterable and streams — so the body is `data: {...}` frames and
     * ->json() cannot read it. Exactly one result frame is emitted, because the
     * messages are accumulated and serialised together.
     *
     * @return array<string, mixed>
     */
    private function promptsGetResult(string $name): array
    {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'prompts/get',
            'params' => ['name' => $name],
        ], ['Accept' => 'application/json, text/event-stream']);

        $response->assertOk();

        $frames = collect(explode("\n\n", trim($response->streamedContent())))
            ->filter(fn (string $frame): bool => str_starts_with($frame, 'data: '))
            ->map(fn (string $frame): mixed => json_decode(substr($frame, 6), true))
            ->values()
            ->all();

        $this->assertCount(1, $frames, "Prompt [{$name}] did not return exactly one result frame.");

        return $frames[0]['result'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function initializePayload(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [],
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
            ],
        ];
    }
}
