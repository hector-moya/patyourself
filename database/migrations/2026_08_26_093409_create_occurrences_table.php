<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One instance of an action — the occasion an outcome attaches to.
 *
 * An action is the standing prescription and its `scheduled_for` is the
 * next-due pointer, which rolls forward on every log. That leaves an occasion
 * that was never logged with no trace at all, so nothing can be caught up and
 * two occasions on the same day are indistinguishable. This table is that
 * missing record: materialised lazily from `actions.series_started_at` up to
 * now, never expired, loggable forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('action_id')->constrained()->cascadeOnDelete();

            // The occasion this row stands for. For an ad hoc log against a
            // cue-anchored action it is the user-supplied occurred_at.
            $table->timestamp('scheduled_for');

            $table->timestamps();

            // Materialisation upserts against this index, which is what makes
            // it idempotent and safe under concurrent reads.
            $table->unique(['action_id', 'scheduled_for']);
            $table->index('scheduled_for');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('occurrences');
    }
};
