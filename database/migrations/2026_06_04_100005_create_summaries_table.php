<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rolling summaries power pattern detection without ML: action-log events are
 * periodically folded into a running text summary, scoped either to a single
 * intention or to the whole account. Each row is a snapshot of the window it
 * covers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Null = account-level summary across all intentions.
            $table->foreignId('intention_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->string('scope')->default('intention'); // intention | user

            $table->text('content');

            $table->timestamp('window_start')->nullable();
            $table->timestamp('window_end')->nullable();

            // How many action-log events were folded into this snapshot.
            $table->unsignedInteger('events_count')->default(0);

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'scope']);
            $table->index('intention_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('summaries');
    }
};
