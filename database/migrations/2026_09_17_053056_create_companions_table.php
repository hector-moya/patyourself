<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the record cannot say about Blob: what it is called, and how much of
 * its earned XP has been spent.
 *
 * This is the first stored thing in a feature whose founding premise was that
 * nothing is stored. The premise is replaced rather than abandoned — STORE ONLY
 * WHAT CANNOT BE DERIVED — and a choice you cannot remember is not a choice.
 *
 * `xp_spent` rather than a balance, which is the whole trick. Earned XP is
 * recomputed from the record on every read, exactly as the ladder's counts are,
 * so the balance is `max(0, earned - spent)` and there is no stored number that
 * can drift out of step with the record it claims to describe. Deleting history
 * lowers `earned` and therefore the balance; the clamp stops it going negative,
 * and skills already learned are never revoked. The worst case is being unable
 * to buy until more is recorded, which takes nothing away. (F1 §3)
 *
 * The row is created lazily on first need, so nothing has to be backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Null reads as "Blob". Nothing changes until someone renames, and
            // clearing the name falls back rather than breaking.
            $table->string('name')->nullable();

            $table->unsignedInteger('xp_spent')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companions');
    }
};
