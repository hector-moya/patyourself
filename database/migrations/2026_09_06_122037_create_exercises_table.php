<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The exercise catalogue: an imported public-domain set, plus anything the
 * user adds themselves.
 *
 * `user_id` is nullable and that is the whole design. A null row is the shared
 * catalogue, visible to everyone; a row with a user is that person's own
 * addition. Nothing is copied per user — seeding once is what makes "start
 * lifting immediately" and "add the machine my gym has" both work without a
 * per-user duplicate of eight hundred barbell movements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercises', function (Blueprint $table) {
            $table->id();

            // Null for the shared catalogue; set for a user's own addition.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            // The source's own slug (e.g. "3_4_Sit-Up"). Unique across the
            // imported set, so re-running the seeder updates rather than
            // duplicates. Null for a user-added exercise, which has no source.
            $table->string('external_id')->nullable()->unique();

            $table->string('name');

            // Nullable because the source leaves them null: `force` in 30 of
            // 876 records, `mechanic` in 87.
            $table->string('category')->nullable();
            $table->string('equipment')->nullable();
            $table->string('force')->nullable();
            $table->string('mechanic')->nullable();
            $table->string('level')->nullable();

            $table->json('primary_muscles')->nullable();
            $table->json('secondary_muscles')->nullable();
            $table->json('instructions')->nullable();

            // v1 ships no images and this stays null. Kept because a null
            // image is already the defined "exercise without a picture" state,
            // so adding pictures later needs no migration.
            $table->string('image_path')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercises');
    }
};
