<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where this action's series began.
 *
 * `scheduled_for` cannot answer that: it is the next-due pointer and rolls
 * forward on every log. This column is set once at creation and never mutated,
 * and it is what materialisation walks forward from.
 *
 * Backfilled from the current `scheduled_for`. For an action that has already
 * rolled forward, that anchor sits in the future, so materialisation produces
 * nothing until it passes — deliberately, because it means the freshly
 * materialised slots can never collide with the occurrences the next migration
 * synthesises for logs written before this branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            $table->timestamp('series_started_at')->nullable()->after('scheduled_for');
        });

        DB::table('actions')
            ->whereNotNull('scheduled_for')
            ->update(['series_started_at' => DB::raw('scheduled_for')]);
    }

    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            $table->dropColumn('series_started_at');
        });
    }
};
