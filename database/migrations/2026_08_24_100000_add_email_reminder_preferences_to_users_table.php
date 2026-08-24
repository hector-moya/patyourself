<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // How the user wants habit cues delivered by email: off, one daily
            // digest, or one email per cue as it fires. Defaults to the
            // low-volume option rather than opting users out — reminders are
            // the product's purpose. See App\Services\Reminders\DigestDispatcher.
            //
            // NOTE: App\Models\User::$attributes mirrors this default and is
            // the one that actually takes effect for Eloquent-created rows —
            // performInsert() sends every currently-set attribute, so the
            // model's default wins for User::create()/factories. This
            // column default only fires for inserts that bypass Eloquent
            // (e.g. raw DB::table('users')->insert()). Changing the value
            // here alone will NOT change app behaviour; update both.
            $table->string('email_reminders')->default('digest')->after('timezone');

            // Local HH:MM the daily digest should be sent at, in the user's own
            // timezone.
            //
            // NOTE: see the same App\Models\User::$attributes caveat above —
            // this default only fires for inserts that bypass Eloquent.
            $table->string('digest_time', 5)->default('07:00')->after('email_reminders');

            // The user's local date the digest was last sent on; the guard that
            // caps delivery at one per day.
            $table->date('digest_last_sent_on')->nullable()->after('digest_time');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['email_reminders', 'digest_time', 'digest_last_sent_on']);
        });
    }
};
