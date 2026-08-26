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
            $table->dropUnique(['occurrence_id']);
            $table->dropConstrainedForeignId('occurrence_id');
            $table->dropColumn(['context', 'context_fields']);
        });
    }
};
