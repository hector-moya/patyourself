<?php

namespace Tests\Feature\Workflows;

use App\Services\Workflows\WorkflowRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Workflows\RegistersSpecFakeWorkflow;
use Tests\Fixtures\Workflows\SpecFakeConfig;
use Tests\Fixtures\Workflows\SpecFakeRecord;
use Tests\TestCase;

/**
 * Naming a workflow the registry does not know must never break anything, and
 * the two ways a lookup can accidentally succeed are both closed here.
 */
class WorkflowRegistryTest extends TestCase
{
    use RefreshDatabase;
    use RegistersSpecFakeWorkflow;

    private WorkflowRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerSpecFakeWorkflow();
        $this->registry = new WorkflowRegistry;
    }

    public function test_the_shipped_registry_is_empty(): void
    {
        // Nothing is plugged in yet. Asserted by subtracting only the fake
        // this test registered and requiring the remainder to be empty —
        // checking a single absent name instead would stay green for any
        // entry that happened to be spelled differently.
        $shipped = config('workflows.registry', []);
        unset($shipped[self::SPEC_FAKE]);

        $this->assertSame([], $shipped);
    }

    public function test_a_registered_name_resolves_to_what_it_attaches(): void
    {
        $definition = $this->registry->for(self::SPEC_FAKE);

        $this->assertNotNull($definition);
        $this->assertSame(self::SPEC_FAKE, $definition->name);
        $this->assertSame('Spec fake', $definition->label);
        $this->assertSame(SpecFakeConfig::class, $definition->config);
        $this->assertSame(SpecFakeRecord::class, $definition->record);
    }

    public function test_null_resolves_to_no_workflow(): void
    {
        $this->assertNull($this->registry->for(null));
        $this->assertFalse($this->registry->has(null));
    }

    public function test_an_unknown_name_resolves_to_no_workflow(): void
    {
        $this->assertNull($this->registry->for('gimnasio'));
        $this->assertFalse($this->registry->has('gimnasio'));
    }

    /**
     * The PHP mirror of the prototype-chain trap scenes.ts already records.
     * `config('workflows.registry.'.$name)` with a dotted name walks into the
     * entry and hands back a nested value, which is truthy and therefore never
     * triggers a `??` fallback — the caller then reads ->record off a string.
     * Reading the array once and using array_key_exists closes it.
     */
    public function test_a_dotted_name_cannot_walk_into_an_entry(): void
    {
        $this->assertNull($this->registry->for(self::SPEC_FAKE.'.label'));
        $this->assertNull($this->registry->for(self::SPEC_FAKE.'.record'));
        $this->assertFalse($this->registry->has(self::SPEC_FAKE.'.label'));
    }

    public function test_names_lists_every_registered_workflow(): void
    {
        $this->assertSame([self::SPEC_FAKE], $this->registry->names());
    }

    /**
     * The registry reads config at call time, not at construction: a workflow
     * registered after the service was resolved must still be found, which is
     * what lets a test register a fake at all.
     */
    public function test_the_registry_is_not_frozen_at_construction(): void
    {
        $registry = new WorkflowRegistry;

        config()->set('workflows.registry.late', [
            'label' => 'Late',
            'config' => null,
            'record' => null,
        ]);

        $this->assertNotNull($registry->for('late'));
    }

    /**
     * Both attachment sites are optional. A workflow with no configuration is
     * not a special case — it is an empty site.
     */
    public function test_a_workflow_may_attach_nothing_at_either_site(): void
    {
        config()->set('workflows.registry.bare', ['label' => 'Bare']);

        $definition = $this->registry->for('bare');

        $this->assertNotNull($definition);
        $this->assertNull($definition->config);
        $this->assertNull($definition->record);
    }
}
