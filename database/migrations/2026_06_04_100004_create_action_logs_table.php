<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Action logs record each completion / failure / skip event. On failure the
 * user-stated reason is captured here — that reason is what drives a strategy
 * to restrategize and shift its intervention point up or down the chain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('action_id')->constrained()->cascadeOnDelete();
            // Denormalized for fast per-user history / pattern queries.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('outcome'); // completed | failed | skipped

            // The user-stated reason, especially on failure.
            $table->text('reason')->nullable();

            // When the event actually happened (may differ from created_at).
            $table->timestamp('logged_at')->useCurrent();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'outcome']);
            $table->index(['action_id', 'logged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_logs');
    }
};
