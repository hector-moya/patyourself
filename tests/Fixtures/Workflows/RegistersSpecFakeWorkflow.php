<?php

namespace Tests\Fixtures\Workflows;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plugs a fake workflow into the registry, with real tables at both extension
 * sites, so the architecture can be tested with nothing real plugged in.
 *
 * The tables are created against the in-memory test database and go away with
 * it. Nothing here ships.
 */
trait RegistersSpecFakeWorkflow
{
    public const SPEC_FAKE = 'spec-fake';

    protected function registerSpecFakeWorkflow(): void
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

        config()->set('workflows.registry.'.self::SPEC_FAKE, [
            'label' => 'Spec fake',
            'config' => SpecFakeConfig::class,
            'record' => SpecFakeRecord::class,
        ]);
    }
}
