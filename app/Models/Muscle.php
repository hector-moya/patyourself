<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Muscle extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description'
    ];

    public function exercises()
    {
        return $this->belongsToMany(Exercise::class);
    }

    public function workouts()
    {
        return $this->belongsToMany(Workout::class);
    }
}
