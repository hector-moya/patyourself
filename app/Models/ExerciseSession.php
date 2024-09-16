<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExerciseSession extends Model
{
    use HasFactory;

    protected $table = 'exercise_session';

    protected $fillable = [
        'exercise_id',
        'reps',
        'weight',
        'user_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function exercise()
    {
        return $this->belongsTo(Exercise::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
