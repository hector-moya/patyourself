<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_advertises_all_five_tools_over_http(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use']);

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ], ['Accept' => 'application/json, text/event-stream']);

        $response->assertOk();

        foreach (['list-loops', 'get-loop', 'today-actions', 'log-action-outcome', 'loop-progress'] as $tool) {
            $this->assertStringContainsString($tool, $response->getContent());
        }
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
