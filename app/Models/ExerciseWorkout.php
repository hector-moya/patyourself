<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExerciseWorkout extends Model
{
    use HasFactory;

    protected $table = 'exercise_workout';

    protected $fillable = [
        'exercise_id',
        'sets',
        'reps',
        'weight',
        'intensity',
        'user_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at'=> 'datetime',
    ];

    public function exercise()
    {
        return $this->belongsTo(Exercise::class);
    }

    public function workout()
    {
        return $this->belongsTo(Workout::class);
    }
}
