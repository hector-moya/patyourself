<?php

namespace App\Models;

use Database\Factories\NoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A freeform observation attached to a loop and to no occasion — something
 * noticed between check-ins that is not an outcome. Append-only, and stored
 * verbatim: it is the user's own words about their own behaviour.
 */
#[Fillable([
    'intention_id',
    'body',
    'noted_at',
])]
class Note extends Model
{
    /** @use HasFactory<NoteFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'noted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Intention, $this> */
    public function intention(): BelongsTo
    {
        return $this->belongsTo(Intention::class);
    }
}
