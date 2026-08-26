<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An outcome attaches to an occurrence, not to an action — that is what dates
 * it by the occasion it describes rather than by the moment it was typed.
 *
 * `action_id` stays, non-null: it is the denormalised parent pointer that
 * LoopProgress::experimentsFor() and Intention::actionLogs() already join on,
 * and keeping it makes this change additive.
 *
 * `context` is the free-text mechanics and remains the primary record;
 * `context_fields` is a deliberately tiny structured set beside it, never a
 * replacement for the user's own words.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_logs', function (Blueprint $table): void {
            $table->foreignId('occurrence_id')
                ->nullable()
                ->after('action_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->text('context')->nullable()->after('reason');
            $table->json('context_fields')->nullable()->after('context');

            // One outcome per occasion.
            $table->unique('occurrence_id');
        });
    }

    public function down(): void
    {
        Schema::table('action_logs', function (Blueprint $table): void {
            // Order matters on MySQL: the unique index is the one satisfying the
            // foreign key's index requirement, so dropping it while the
            // constraint still exists fails with "needed in a foreign key
            // constraint". Drop the constraint first, then the index, then the
            // columns.
            $table->dropForeign(['occurrence_id']);
            $table->dropUnique(['occurrence_id']);
            $table->dropColumn(['occurrence_id', 'context', 'context_fields']);
        });
    }
};
