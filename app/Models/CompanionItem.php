<?php

namespace App\Models;

use Database\Factories\CompanionItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stack in the bag.
 *
 * Its category is config's to say, not this row's — see the migration. Nothing
 * here is ever destroyed: materials transform, so spending fibre on a basket is
 * not losing fibre, it is the basket having fibre in it.
 */
#[Fillable([
    'item',
    'quantity',
])]
class CompanionItem extends Model
{
    /** @use HasFactory<CompanionItemFactory> */
    use HasFactory;

    /** @return BelongsTo<Companion, $this> */
    public function companion(): BelongsTo
    {
        return $this->belongsTo(Companion::class);
    }
}
