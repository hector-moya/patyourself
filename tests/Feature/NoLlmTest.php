<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * The app authors nothing with a model of its own. The coach became the MCP
 * surface, where the model belongs to the client — so no key, no agent, no
 * conversation store, and no dependency that would quietly reintroduce one.
 */
class NoLlmTest extends TestCase
{
    public function test_the_application_has_no_ai_layer(): void
    {
        $this->assertDirectoryDoesNotExist(app_path('Ai'));
        $this->assertFileDoesNotExist(config_path('ai.php'));
    }

    /**
     * `laravel/ai` is gone, and this is what keeps it gone.
     *
     * While the package was still installed this assertion could not be
     * written: its auto-discovered service provider merged the package's own
     * defaults, so `config('ai')` stayed non-null even with no agents and no
     * published config file. Removing the dependency is what made the obvious
     * assertion true, and it is the one that goes red if the package returns.
     */
    public function test_no_ai_package_is_installed(): void
    {
        $this->assertNull(config('ai'));

        // Named as a string, not `::class`. Pint's fully_qualified_strict_types
        // fixer rewrites an inline `::class` into a real `use` import, which
        // would leave this file importing the very package it asserts is gone.
        $this->assertFalse(class_exists('Laravel\Ai\Concerns\HasConversations'));
    }

    /**
     * The conversation store went with the package. Asserted through the model
     * rather than against the schema: `agent_conversations` no longer exists in
     * any migration, so a schema assertion would be a predicate about a name
     * nothing defines — the kind that passes forever on SQLite.
     */
    public function test_the_user_model_carries_no_conversation_store(): void
    {
        $this->assertFalse(method_exists(User::class, 'conversations'));
    }
}
