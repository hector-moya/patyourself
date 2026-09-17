<?php

namespace App\Models;

use Database\Factories\CompanionNodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One node in the clearing, and what is standing there.
 *
 * The row existing at all means Blob has MET this node — that is the encounter
 * which reveals its skill, and it is why a row can sit at zero forever without
 * being a lock or an empty slot. `available` is what has accrued since the
 * skill was learned, and nothing about it ever expires.
 */
#[Fillable([
    'node',
    'available',
])]
class CompanionNode extends Model
{
    /** @use HasFactory<CompanionNodeFactory> */
    use HasFactory;

    /** @return BelongsTo<Companion, $this> */
    public function companion(): BelongsTo
    {
        return $this->belongsTo(Companion::class);
    }
}
