<?php

namespace Tests\Feature\Training;

use App\Models\ActionExercise;
use App\Models\PerformedSet;
use App\Services\Workflows\WorkflowRegistry;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

/**
 * Gym is the first real entry in the workflow registry. Its definition names
 * two model class-strings by name in `config/workflows.php`, which nothing
 * checks at boot time — a typo'd class would resolve happily here and only
 * fatal the first time something actually tried to query or create through
 * it, in production, at the two extension sites. This pins both class names
 * and that both are genuine Eloquent models.
 *
 * No database is needed: nothing here writes a row, only resolves and
 * inspects class-strings.
 */
class GymWorkflowRegistryTest extends TestCase
{
    public function test_gym_resolves_to_the_action_exercise_and_performed_set_models(): void
    {
        $definition = (new WorkflowRegistry)->for('gym');

        $this->assertNotNull($definition);
        $this->assertSame('Gym', $definition->label);
        $this->assertSame(ActionExercise::class, $definition->config);
        $this->assertSame(PerformedSet::class, $definition->record);
    }

    public function test_the_config_class_exists_and_is_an_eloquent_model(): void
    {
        $definition = (new WorkflowRegistry)->for('gym');
        $this->assertNotNull($definition);

        $this->assertTrue(class_exists((string) $definition->config));
        $this->assertTrue(is_subclass_of((string) $definition->config, Model::class));
    }

    public function test_the_record_class_exists_and_is_an_eloquent_model(): void
    {
        $definition = (new WorkflowRegistry)->for('gym');
        $this->assertNotNull($definition);

        $this->assertTrue(class_exists((string) $definition->record));
        $this->assertTrue(is_subclass_of((string) $definition->record, Model::class));
    }
}
