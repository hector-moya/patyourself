<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fire guard moves from the action row onto the occasion.
 *
 * `actions.status` could only ever hold one live slot, so firing a series meant
 * flipping the same row pending -> active -> pending forever. An occasion fires
 * once, and `fired_at` records when — which is both the idempotency guard and
 * the honest answer to "was the cue delivered for this occasion?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('occurrences', function (Blueprint $table): void {
            $table->timestamp('fired_at')->nullable()->after('scheduled_for');
            $table->index('fired_at');
        });
    }

    public function down(): void
    {
        Schema::table('occurrences', function (Blueprint $table): void {
            $table->dropIndex(['fired_at']);
            $table->dropColumn('fired_at');
        });
    }
};
