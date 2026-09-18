<?php

use App\Actions\BuildShelter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Blob has built, and whether the cabin the record used to grant has
 * already been handed back as materials.
 *
 * `shelter` is a single value rather than a collection because the stages
 * REPLACE one another: building the hut is the lean-to becoming a hut, and the
 * lean-to's planks are in it. It only ever advances — nothing in the
 * application lowers it, and {@see BuildShelter} is its only
 * writer. Null is "nothing built", which is where every account starts.
 *
 * WHERE BLOB IS is deliberately NOT here. Inside or outside is where you are
 * looking, not something you own, and it resets to outside on load the way a
 * modal does. What is built and where Blob stands are independent facts — you
 * can stand outside a cabin or sit inside a lean-to — and conflating them is
 * what the arc's own sentence about this phase got wrong. (F3 §1, §3)
 *
 * `salvaged_at` is the mark that an established account's cabin was already
 * converted. It has to be stored: the heap is deleted once it is drained, so
 * the heap's own existence cannot answer the question, and an unmarked account
 * would be handed a second cabin's worth of planks. It is a choice the app
 * made rather than a claim about the record, which is what keeps it inside
 * "store only what cannot be derived".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companions', function (Blueprint $table): void {
            $table->string('shelter')->nullable()->after('name');
            $table->timestamp('salvaged_at')->nullable()->after('xp_spent');
        });
    }

    public function down(): void
    {
        Schema::table('companions', function (Blueprint $table): void {
            $table->dropColumn(['shelter', 'salvaged_at']);
        });
    }
};
