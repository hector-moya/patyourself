<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The gym workflow's config extension site: what a set of occasions is meant
 * to contain, one row per exercise in the routine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('action_id')->constrained()->cascadeOnDelete();

            // Restrict, not cascade: deleting a catalogue entry must not
            // silently empty someone's routine. Deletion hides an exercise
            // from new templates; it does not rewrite the ones already
            // written.
            $table->foreignId('exercise_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('position');
            $table->unsignedInteger('target_sets');

            // A single integer, not a range. Real routines say "8-12"; v1
            // says 10. A range is two columns and a display rule, and can be
            // added without touching anything written here. Chosen, not
            // overlooked.
            $table->unsignedInteger('target_reps');

            $table->timestamps();

            $table->unique(['action_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_exercises');
    }
};
