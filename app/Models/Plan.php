<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'objective_id',
        'image_path',
    ];

    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class);
    }

    public function workouts(): BelongsToMany
    {
        return $this->belongsToMany(Workout::class);
    }

    public function users() : BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
