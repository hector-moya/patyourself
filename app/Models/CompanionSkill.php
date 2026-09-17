<?php

namespace App\Models;

use Database\Factories\CompanionSkillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing Blob chose to learn.
 *
 * Append-only and never revoked — see the `companions` migration for why a
 * balance that falls below what was spent takes nothing away.
 *
 * `learned_at` is the epoch for this skill's node: stock accrues only from
 * here, which is what stops an established record arriving to a full clearing.
 */
#[Fillable([
    'name',
    'learned_at',
])]
class CompanionSkill extends Model
{
    /** @use HasFactory<CompanionSkillFactory> */
    use HasFactory;

    /** @return BelongsTo<Companion, $this> */
    public function companion(): BelongsTo
    {
        return $this->belongsTo(Companion::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'learned_at' => 'immutable_datetime',
        ];
    }
}
