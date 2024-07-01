<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'objective_id'
    ];

    public function objective()
    {
        return $this->belongsTo(Objective::class);
    }

    public function workouts()
    {
        return $this->belongsToMany(Workout::class);
    }
}
