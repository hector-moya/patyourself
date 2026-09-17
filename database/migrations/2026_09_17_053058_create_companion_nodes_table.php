<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What is standing in the clearing, waiting to be picked up.
 *
 * `available` is the one stored value in this feature that could in principle
 * be derived, and it is stored anyway. The exception is deliberate: THE WORLD IS
 * A PLACE, NOT A SUMMARY OF THE RECORD. It is mutated by harvesting, and
 * replaying every log to reconstruct it would buy purity at the cost of a write
 * path that has to agree with a read path. (F1 §5)
 *
 * A row also records that Blob has MET this node. Clicking a node without its
 * skill does not fail and does not show a lock — Blob turns it over and puts it
 * down again, and that encounter writes a row at zero, which is what puts the
 * skill in the list. (F1 §2)
 *
 * Nothing here expires. Away for two weeks, it is all still there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companion_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('companion_id')->constrained()->cascadeOnDelete();

            $table->string('node');
            $table->unsignedInteger('available')->default(0);

            $table->timestamps();

            $table->unique(['companion_id', 'node']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companion_nodes');
    }
};
