<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Strategies are versioned interventions on an intention. History is never
 * rewritten in place: each shift creates a new version that supersedes the
 * previous one, recording WHY (stacked on success / restrategized on a
 * user-stated failure) and WHERE in the cue->craving->response->reward chain
 * the new version intervenes. Lineage is tracked via parent_strategy_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intention_id')->constrained()->cascadeOnDelete();

            // Monotonic per-intention version number (1, 2, 3...).
            $table->unsignedInteger('version')->default(1);

            // Only one 'active' version per intention (enforced in app logic).
            $table->string('status')->default('active');

            // Point in the behavioral chain this version intervenes on.
            $table->string('intervention_point')->default('response');

            $table->text('approach');
            $table->text('rationale')->nullable();

            // Lineage to the version this one derived from / superseded.
            $table->foreignId('parent_strategy_id')
                ->nullable()
                ->constrained('strategies')
                ->nullOnDelete();

            // Why this version was created.
            $table->string('change_reason')->default('initial');

            // User-stated reason captured when this version was superseded.
            $table->text('superseded_reason')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique(['intention_id', 'version']);
            $table->index(['intention_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategies');
    }
};
