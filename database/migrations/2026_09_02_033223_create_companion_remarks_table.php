<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Blob has to say, written by the coach through MCP and relayed on
 * /companion.
 *
 * Append-only: there is no edit and no delete, like every other record in this
 * app. A remark whose loop is paused or archived simply stops being eligible to
 * show; the row stays.
 *
 * `intention_id` is nullable — a remark with no loop is general and stays
 * eligible forever. It cascades rather than nulling on delete, matching
 * `notes`: deleting a loop is the one destructive act in the app and it takes
 * the loop's whole record with it, and a remark about a loop that no longer
 * exists would otherwise be silently promoted to a general one.
 *
 * The composite index leads on `user_id` — every read is scoped to one user —
 * and carries `intention_id` because the eligibility read filters on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companion_remarks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('intention_id')->nullable()->constrained()->cascadeOnDelete();

            $table->text('body');

            $table->timestamps();

            $table->index(['user_id', 'intention_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companion_remarks');
    }
};
