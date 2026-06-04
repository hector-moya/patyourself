<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Actions are the concrete things a strategy prescribes the user to do — the
 * source of the rendered action cards. Each action belongs to the strategy
 * version that produced it, so superseding a strategy doesn't mutate past
 * actions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intention_id')->constrained()->cascadeOnDelete();
            $table->foreignId('strategy_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->timestamp('scheduled_for')->nullable();
            // Simple recurrence rule (e.g. "daily", "weekdays"); null = one-off.
            $table->string('recurrence')->nullable();

            $table->string('status')->default('pending');

            // AI-authored structured payload the action card renders.
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['intention_id', 'status']);
            $table->index('strategy_id');
            $table->index('scheduled_for');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actions');
    }
};
