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

    /**
     * Mirrors the migration's default so a row created by an encounter already
     * reads as zero instead of null until it is re-fetched — the encounter
     * returns the row it just wrote, and an empty node has to say "nothing
     * here" rather than "unknown". Same reasoning as {@see Companion::$attributes}.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'available' => 0,
    ];

    /** @return BelongsTo<Companion, $this> */
    public function companion(): BelongsTo
    {
        return $this->belongsTo(Companion::class);
    }
}
