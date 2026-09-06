<?php

namespace App\Models;

use Database\Factories\PerformedSetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The gym workflow's record extension site: one set actually performed
 * during a session.
 *
 * Keyed to the occurrence, not the log — sets are ticked off during a
 * session, long before anyone presses Done or Missed, and it is the
 * occurrence that exists at that point.
 *
 * `weight` is kilograms, and null means body weight — "not applicable",
 * never zero: zero is a weight, and a progression read cannot tell them
 * apart.
 */
#[Fillable([
    'occurrence_id',
    'exercise_id',
    'set_number',
    'reps',
    'weight',
])]
class PerformedSet extends Model
{
    /** @use HasFactory<PerformedSetFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Occurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(Occurrence::class);
    }

    /** @return BelongsTo<Exercise, $this> */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}
