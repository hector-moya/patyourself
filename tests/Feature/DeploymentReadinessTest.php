<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards the production deploy: Forge's deploy script caches config and routes,
 * which fails hard if two routes share a name or a config value isn't
 * serializable. These run those commands so a regression is caught in CI, not on
 * the server.
 */
class DeploymentReadinessTest extends TestCase
{
    public function test_routes_can_be_cached_for_production(): void
    {
        try {
            $this->artisan('route:cache')->assertSuccessful();
        } finally {
            $this->artisan('route:clear')->assertSuccessful();
        }
    }

    public function test_config_can_be_cached_for_production(): void
    {
        try {
            $this->artisan('config:cache')->assertSuccessful();
        } finally {
            $this->artisan('config:clear')->assertSuccessful();
        }
    }
}
