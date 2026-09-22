<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What is at home: one stack per item, standing in the chest.
 *
 * SAME SHAPE AS `companion_items`, AND DELIBERATELY NOT THE SAME TABLE. A
 * `location` column would have been fewer lines and would have been wrong:
 * `Companion::held()` and `Companion::capacity()` both sum `$this->items`
 * unfiltered, so a stashed crate would raise carry capacity and stashed planks
 * would count against the bag — silently, because every test written before
 * this one puts every row in one place.
 *
 * A separate relation cannot do that. `items` keeps meaning what it has always
 * meant, by construction rather than by a filter somebody has to remember.
 *
 * As in the bag, `item` is a name and its CATEGORY IS CONFIG'S TO SAY. There is
 * no capacity column because the stash has no ceiling: the world is uncapped
 * everywhere else in this feature — node stock has none and nothing expires —
 * and a limit here would be the first place the world refuses to hold
 * something.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companion_stash_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('companion_id')->constrained()->cascadeOnDelete();

            $table->string('item');
            $table->unsignedInteger('quantity');

            $table->timestamps();

            $table->unique(['companion_id', 'item']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companion_stash_items');
    }
};
