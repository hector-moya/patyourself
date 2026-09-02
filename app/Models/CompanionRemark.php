<?php

namespace App\Models;

use Database\Factories\CompanionRemarkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing Blob has to say, written by the coach and relayed verbatim.
 *
 * This is the half of D2 that reverses an earlier ruling knowingly: the words
 * are the coach's now, not the app's. The app still makes no model calls — the
 * coach runs outside it and writes through MCP, which is the same boundary
 * every other write already crosses.
 *
 * Append-only. A remark attached to a loop stops being eligible when that loop
 * stops being active; it is never edited and never removed.
 */
#[Fillable([
    'intention_id',
    'body',
])]
class CompanionRemark extends Model
{
    /** @use HasFactory<CompanionRemarkFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Intention, $this> */
    public function intention(): BelongsTo
    {
        return $this->belongsTo(Intention::class);
    }
}
