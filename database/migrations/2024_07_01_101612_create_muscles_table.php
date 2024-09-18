<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('muscles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('muscle_workout', function (Blueprint $table) {
            $table->id();
            $table->foreignId('muscle_id')->constrained();
            $table->foreignId('workout_id')->constrained();
        });

        Schema::create('exercise_muscle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('muscle_id')->constrained();
            $table->foreignId('exercise_id')->constrained();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('muscles');
    }
};
