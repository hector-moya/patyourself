<?php

namespace Tests\Fixtures\Workflows;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plugs a fake workflow into the registry, with real tables at both extension
 * sites, so the architecture can be tested with nothing real plugged in.
 *
 * Registering the config entry and creating the tables are two unrelated
 * things: a test that only resolves definitions from the registry never
 * writes a config or record row and needs no database at all, so it should
 * not be forced to carry `RefreshDatabase` for tables it never touches. A test
 * that does write through `SpecFakeConfig` or `SpecFakeRecord` needs both.
 *
 * The tables are created against the in-memory test database and go away with
 * it. Nothing here ships.
 */
trait RegistersSpecFakeWorkflow
{
    public const SPEC_FAKE = 'spec-fake';

    /** The config entry and the tables it points at — for tests that write to either. */
    protected function registerSpecFakeWorkflow(): void
    {
        $this->registerSpecFakeWorkflowConfig();
        $this->createSpecFakeWorkflowTables();
    }

    /** The registry entry alone. No database required. */
    protected function registerSpecFakeWorkflowConfig(): void
    {
        config()->set('workflows.registry.'.self::SPEC_FAKE, [
            'label' => 'Spec fake',
            'config' => SpecFakeConfig::class,
            'record' => SpecFakeRecord::class,
        ]);
    }

    /** The tables at each extension site, with nothing registered to point at them. */
    protected function createSpecFakeWorkflowTables(): void
    {
        Schema::create('spec_fake_configs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('action_id');
            $table->string('body');
            $table->timestamps();
        });

        Schema::create('spec_fake_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('occurrence_id');
            $table->string('body');
            $table->timestamps();
        });
    }
}
