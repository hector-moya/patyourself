<?php

namespace Tests\Feature\Mcp;

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
            ['list-loops', 'get-loop', 'today-actions', 'pending-outcomes', 'log-outcome', 'loop-outcomes', 'loop-progress', 'create-loop', 'start-experiment', 'add-action', 'update-action', 'remove-action', 'update-loop', 'log-note'],
            array_column($response->json('result.tools'), 'name'),
        );
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

    private function toolsList(): TestResponse
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ], ['Accept' => 'application/json, text/event-stream']);
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
