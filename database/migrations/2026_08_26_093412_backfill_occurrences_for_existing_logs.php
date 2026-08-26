<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every outcome written before this branch needs the occasion it describes.
 *
 * Pragmatic by design: the existing record is one loop with one logged
 * failure, and there is nothing here worth contorting the schema to preserve.
 * Each existing log gets a synthesised occurrence dated at its `logged_at` —
 * the closest thing to the occasion that the old model recorded at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        $logs = DB::table('action_logs')
            ->whereNull('occurrence_id')
            ->orderBy('id')
            ->get(['id', 'action_id', 'logged_at']);

        foreach ($logs as $log) {
            $existing = DB::table('occurrences')
                ->where('action_id', $log->action_id)
                ->where('scheduled_for', $log->logged_at)
                ->value('id');

            $occurrenceId = $existing ?? DB::table('occurrences')->insertGetId([
                'action_id' => $log->action_id,
                'scheduled_for' => $log->logged_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('action_logs')
                ->where('id', $log->id)
                ->update(['occurrence_id' => $occurrenceId]);
        }
    }

    public function down(): void
    {
        // The synthesised occurrences go with the table itself; nothing to undo.
    }
};
