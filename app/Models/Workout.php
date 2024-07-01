<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Workout extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'category_id'
    ];

    public function exercises()
    {
        return $this->belongsToMany(Exercise::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function plans()
    {
        return $this->belongsToMany(Plan::class);
    }

    public function muscles()
    {
        return $this->morphMany(Muscle::class, 'musculable');
    }

}
