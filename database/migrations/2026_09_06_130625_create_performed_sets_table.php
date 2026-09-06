<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The gym workflow's record extension site: what actually happened during a
 * session, one row per set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performed_sets', function (Blueprint $table) {
            $table->id();

            // Keyed to the occasion, not the log. Sets are ticked off DURING
            // a session, long before anyone presses Done or Missed — at
            // which point there is no ActionLog to attach them to. Creating
            // one early to hold them would pay the user for walking into the
            // gym, and logCount is Blob's fuel.
            $table->foreignId('occurrence_id')->constrained()->cascadeOnDelete();

            $table->foreignId('exercise_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('set_number');
            $table->unsignedInteger('reps');

            // Kilograms. NULL means body weight — "not applicable", never
            // zero: zero is a weight, and a progression read cannot tell
            // them apart.
            $table->decimal('weight', 6, 2)->nullable();

            $table->timestamps();

            $table->unique(['occurrence_id', 'exercise_id', 'set_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performed_sets');
    }
};
