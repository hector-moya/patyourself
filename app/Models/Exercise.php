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
        'image_path'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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
    public function exerciseSessions()
    {
        return $this->hasMany(ExerciseSession::class);
    }

    public function targetMuscle()
    {
        return $this->belongsTo(Muscle::class, 'target_muscle_id');
    }
}
