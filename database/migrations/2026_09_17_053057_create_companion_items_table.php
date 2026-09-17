<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bag: one stack per item.
 *
 * `item` is a name, and its CATEGORY IS LOOKED UP IN CONFIG rather than stored
 * beside it — see `config('companion.bag')`. A category written into a row is a
 * second copy of something config already decides, and the two would eventually
 * disagree about what a thing is.
 *
 * This is not the four-wearable cap in `config('companion.item_types')`. That
 * cap was always about clutter on a 64x64 sprite and it stands; nothing stored
 * here is drawn on Blob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companion_items', function (Blueprint $table): void {
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
        Schema::dropIfExists('companion_items');
    }
};
