<?php

namespace Tests\Feature\Mcp;

use Tests\TestCase;

/**
 * OAuth dynamic client registration must only ever hand out authorization
 * codes for redirect URIs we recognize — a wildcard here is an auth-code
 * phishing vector.
 */
class McpConfigTest extends TestCase
{
    public function test_redirect_domains_do_not_allow_the_wildcard(): void
    {
        $this->assertNotContains('*', config('mcp.redirect_domains'));
    }

    public function test_redirect_domains_include_the_claude_hosts(): void
    {
        $this->assertContains('https://claude.ai', config('mcp.redirect_domains'));
        $this->assertContains('https://claude.com', config('mcp.redirect_domains'));
    }
}
