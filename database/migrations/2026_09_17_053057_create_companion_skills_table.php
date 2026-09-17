<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Blob chose to learn, and when.
 *
 * `learned_at` is not decoration: it is the epoch for this skill's node. Stock
 * accrues only from the moment the skill was learned, which is what stops a
 * long-established account arriving to a clearing holding a thousand units.
 * XP is retroactive over the whole record; the world is deliberately not.
 * (F1 §4)
 *
 * Append-only, and never revoked — not even when deleting history takes the
 * balance below what was spent on it. Nothing in this feature has ever
 * regressed and this is not where that starts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companion_skills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('companion_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->timestamp('learned_at');

            $table->timestamps();

            // A skill is learned once. Enforced here rather than by care, so a
            // double-submitted click cannot buy the same thing twice.
            $table->unique(['companion_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companion_skills');
    }
};
