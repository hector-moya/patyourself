<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intentions are the "loops" a user is building or breaking. Each models the
 * habit anatomy — cue -> craving -> response -> reward — that strategies
 * later intervene on. The structured object the LLM authors lives here; the
 * UI only renders it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // Building a good habit vs. breaking a bad one.
            $table->string('type')->default('build');
            $table->string('status')->default('active');

            // Habit anatomy (Atomic Habits chain).
            $table->text('cue')->nullable();
            $table->text('craving')->nullable();
            $table->text('response')->nullable();
            $table->text('reward')->nullable();

            // Extra AI-authored structured payload.
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intentions');
    }
};
