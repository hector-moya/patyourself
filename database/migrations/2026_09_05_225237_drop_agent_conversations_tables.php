<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Removes the last of the in-app coach: the two tables `laravel/ai` used to
 * store agent conversations. Nothing has written to or read from them since the
 * app went zero-LLM, and the package that created them is gone as of this
 * commit — its migration extended `Laravel\Ai\Migrations\AiMigration`, so it
 * could not survive the removal and was deleted rather than left to fatal.
 *
 * These tables are not empty. They hold the transcripts of the coaching
 * conversations that authored the earliest loops, which is history this app's
 * whole premise says not to throw away — so the rows are written out before the
 * tables go, and only then dropped.
 *
 * The export runs here rather than in a command the deploy could forget. It
 * lands in `storage/app/private/` — the `local` disk's root, which is
 * `storage_path('app/private')` and not `storage/app` — and survives a Forge
 * deploy. On a fresh database (tests, a new checkout) the tables do not exist
 * and the whole migration is a no-op that writes nothing.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['agent_conversations', 'agent_conversation_messages'];

    public function up(): void
    {
        $this->archive();

        // Messages first: they carry the conversation_id.
        Schema::dropIfExists('agent_conversation_messages');
        Schema::dropIfExists('agent_conversations');
    }

    /**
     * Irreversible by design. Re-creating empty tables would restore the shape
     * and not the transcripts, which is worse than leaving them gone — the
     * archive written by `up()` is the real way back.
     */
    public function down(): void {}

    /**
     * Write every row to a timestamped JSON file before the tables go.
     *
     * Skipped entirely when there is nothing to archive, so a fresh database
     * leaves no stray file behind.
     */
    private function archive(): void
    {
        $rows = [];

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows[$table] = DB::table($table)->get()->all();
        }

        if ($rows === [] || array_sum(array_map('count', $rows)) === 0) {
            return;
        }

        Storage::disk('local')->put(
            'coach-transcripts-'.now()->format('Y-m-d-His').'.json',
            (string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
};
