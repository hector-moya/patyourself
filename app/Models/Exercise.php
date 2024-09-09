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
        return $this->belongsToMany(Muscle::class);
    }

    public function exercises()
    {
        return $this->belongsToMany(User::class)->withPivot('created_at');
    }
}
