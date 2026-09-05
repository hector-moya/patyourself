<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which recording surface a loop uses, chosen from the registry in
 * config/workflows.php rather than typed.
 *
 * Nullable, and null is the ordinary case: a loop with no workflow is the plain
 * loop this app has always had, and stays the overwhelming majority forever.
 * Persisted rather than derived from "does it have any records yet" for two
 * reasons — the intent has to exist before the data does, or nothing ever
 * offers you the chance to create the data; and comparing loops by workflow
 * should be a `group by`, not a guess at which tables happen to have rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intentions', function (Blueprint $table) {
            $table->string('workflow')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('intentions', function (Blueprint $table) {
            $table->dropColumn('workflow');
        });
    }
};
