<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A strategy version is an experiment: it runs for a planned length and
     * ends with a verdict.
     *
     * All three are nullable, and existing rows keep null — which reads
     * correctly as "open-ended, never concluded". `verdict_note` is separate
     * from `superseded_reason` because a strategy concluded as `worked` is
     * never superseded, so its note would otherwise live permanently in a
     * column whose name denies it.
     */
    public function up(): void
    {
        Schema::table('strategies', function (Blueprint $table): void {
            $table->dateTime('review_at')->nullable()->after('superseded_reason');
            $table->string('verdict')->nullable()->after('review_at');
            $table->text('verdict_note')->nullable()->after('verdict');
        });
    }

    public function down(): void
    {
        Schema::table('strategies', function (Blueprint $table): void {
            $table->dropColumn(['review_at', 'verdict', 'verdict_note']);
        });
    }
};
