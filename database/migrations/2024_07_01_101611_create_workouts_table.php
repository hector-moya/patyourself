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
        Schema::create('workouts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('image_path')->nullable();
            $table->foreignId('category_id')->constrained();
            $table->timestamps();
        });

        Schema::create('exercise_workout', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_id')->constrained();
            $table->foreignId('exercise_id')->constrained();
            $table->string('date')->default(now());
        });

        Schema::create('plan_workout', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_id')->constrained();
            $table->foreignId('plan_id')->constrained();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workouts');
    }
};
