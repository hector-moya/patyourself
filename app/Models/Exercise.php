<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Exercise extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'sets',
        'reps'
    ];

    public function workouts()
    {
        return $this->belongsToMany(Workout::class)
                    ->withPivot('date');
    }

    public function muscles()
    {
        return $this->morphMany(Muscle::class, 'musculable');
    }
}
