<?php

namespace App\Models;

use Database\Factories\CompanionStashItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stack at home, in the chest.
 *
 * The bag's twin, and its own table for a measured reason the migration
 * records. Its category is config's to say, not this row's.
 *
 * Nothing here is destroyed: a stack moved back into the bag is the same stack
 * in a different place, and a stack drained to nothing has its row removed
 * rather than left at zero — the same rule the bag already follows, because an
 * empty row would render as a line saying Blob has no planks at home.
 */
#[Fillable([
    'item',
    'quantity',
])]
class CompanionStashItem extends Model
{
    /** @use HasFactory<CompanionStashItemFactory> */
    use HasFactory;

    /** @return BelongsTo<Companion, $this> */
    public function companion(): BelongsTo
    {
        return $this->belongsTo(Companion::class);
    }
}
