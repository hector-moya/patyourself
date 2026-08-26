<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A freeform observation attached to a loop and to no occasion — the things
 * noticed between check-ins that are not outcomes.
 *
 * Deliberately not `summaries`. A summary is a single rolling narrative:
 * ProgressController reads `latestSummary` and progress/show.tsx renders it as
 * one block of prose. Appending discrete, timestamped observations there would
 * turn that narrative into an accidental log and break the rendering. The two
 * are different things and stay apart.
 *
 * Append-only, like everything else that records what happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intention_id')->constrained()->cascadeOnDelete();

            $table->text('body');

            // When the observation was made — which may predate when it was
            // typed, since these are usually recalled at a check-in.
            $table->timestamp('noted_at');

            $table->timestamps();

            $table->index(['intention_id', 'noted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
