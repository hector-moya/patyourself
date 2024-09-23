<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Exercise extends Model
{
    use HasFactory;

    protected $fillable = [
        'exercisedb_id',	
        'name',
        'description',
        'target_muscle_id',
        'sets',
        'reps',
        'image_path'
    ];

    public function workouts()
    {
        return $this->belongsToMany(Workout::class)
                    ->withPivot('date');
    }

    public function muscles()
    {
        return $this->belongsToMany(Muscle::class);
    }

    public function exercises()
    {
        return $this->belongsToMany(User::class)->withPivot('created_at');
    }

    public function exerciseSession()
    {
        return $this->hasMany(ExerciseSession::class);
    }
}
