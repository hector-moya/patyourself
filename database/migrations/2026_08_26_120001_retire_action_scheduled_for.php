<?php

use App\Models\Action;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retires the next-due cursor and the per-occasion action statuses.
 *
 * `scheduled_for` was the one answer to "what is due", and it was lossy:
 * Schedule::nextAfter() fast-forwarded past every missed slot, so a miss
 * vanished. Occurrences carry the whole grid and are now the only answer.
 *
 * The status values go with it. `pending -> active -> completed -> pending` was
 * the roll-forward cycle itself; a standing prescription is either live or
 * archived, and whether one of its occasions has been answered is a fact about
 * the occasion.
 *
 * Nothing here is unrecoverable: `scheduled_for` is derivable from
 * `series_started_at` + `recurrence`, and the retired statuses are derivable
 * from an action's logs and its occasions. down() restores the model, not each
 * row's exact prior value.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('actions')
            ->where('status', '!=', Action::STATUS_ARCHIVED)
            ->update(['status' => Action::STATUS_ACTIVE]);

        Schema::table('actions', function (Blueprint $table): void {
            $table->dropIndex(['scheduled_for']);
            $table->dropColumn('scheduled_for');

            // The column default was 'pending', a value that no longer exists.
            // Left alone, any insert that omits status would write a status the
            // application cannot interpret.
            $table->string('status')->default(Action::STATUS_ACTIVE)->change();
        });
    }

    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            $table->timestamp('scheduled_for')->nullable()->after('description');
            $table->index('scheduled_for');
            $table->string('status')->default('pending')->change();
        });

        // The cursor's meaning was "the next slot at or after now", which the
        // anchor and the recurrence still describe exactly.
        DB::table('actions')
            ->whereNotNull('series_started_at')
            ->update(['scheduled_for' => DB::raw('series_started_at')]);

        DB::table('actions')
            ->where('status', '!=', Action::STATUS_ARCHIVED)
            ->update(['status' => 'pending']);
    }
};
