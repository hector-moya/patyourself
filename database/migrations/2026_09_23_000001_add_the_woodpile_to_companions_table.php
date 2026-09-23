<?php

use App\Actions\StackWood;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much wood is stacked against the shelter's wall.
 *
 * ONE INTEGER RATHER THAN A TABLE, because nothing ever comes back out. A pile
 * that could be emptied would be a third place to keep things, and the chest is
 * already the second. With no withdrawal there is nothing to remember about
 * what went in, so there is nothing to give rows to.
 *
 * It is a CHOICE rather than a count of the record — the same standing
 * `xp_spent` and `shelter` already have, and the reason "store only what cannot
 * be derived" permits it. Nothing about it reads how much has been logged, and
 * nothing may.
 *
 * It only ever rises. {@see StackWood} is its only writer, and there is no
 * route in the application that lowers it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companions', function (Blueprint $table): void {
            $table->unsignedInteger('woodpile')->default(0)->after('shelter');
        });
    }

    public function down(): void
    {
        Schema::table('companions', function (Blueprint $table): void {
            $table->dropColumn('woodpile');
        });
    }
};
