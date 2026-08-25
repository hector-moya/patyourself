<?php

namespace Tests\Feature;

use Tests\TestCase;

class NoLlmTest extends TestCase
{
    public function test_the_application_has_no_ai_layer(): void
    {
        // config('ai') is deliberately NOT asserted here. laravel/ai's
        // auto-discovered service provider merges the package's own default
        // config regardless of whether this app publishes config/ai.php, so
        // config('ai') stays non-null even with no agents and no published
        // config file. Re-adding assertNull(config('ai')) will fail forever.
        $this->assertDirectoryDoesNotExist(app_path('Ai'));
        $this->assertFileDoesNotExist(config_path('ai.php'));
    }
}
