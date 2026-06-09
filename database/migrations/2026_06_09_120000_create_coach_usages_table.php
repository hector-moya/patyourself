<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An immutable ledger of every server-side LLM call: who made it, the model, the
 * token counts, and what it was for. It is the audit trail behind the cost
 * guard — a user's rolling-window token budget is enforced by summing these
 * rows — and the raw material for usage / cost reporting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('model');
            $table->string('purpose')->nullable(); // chat | authoring | summary | strategy | …

            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);

            $table->timestamps();

            // The cost guard sums a user's recent tokens; index that lookup.
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coach_usages');
    }
};
