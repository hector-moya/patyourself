<?php

namespace App\Models;

use Database\Factories\ExerciseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement in the catalogue.
 *
 * A row with no `user_id` belongs to the shared imported set; one with a
 * `user_id` is that person's own addition. Deleting an exercise never removes
 * the sets performed against it — history does not vanish because a name was
 * tidied up.
 */
#[Fillable([
    'user_id',
    'external_id',
    'name',
    'category',
    'equipment',
    'force',
    'mechanic',
    'level',
    'primary_muscles',
    'secondary_muscles',
    'instructions',
    'image_path',
])]
class Exercise extends Model
{
    /** @use HasFactory<ExerciseFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'primary_muscles' => 'array',
            'secondary_muscles' => 'array',
            'instructions' => 'array',
        ];
    }

    /**
     * The shared catalogue plus this user's own additions, and nobody else's.
     *
     * The two conditions are grouped in their own closure so that composing
     * this scope with any other `where` (e.g. filtering by category) does not
     * let the outer `orWhere` escape the group and pull in every user's rows.
     * Eloquent's local-scope machinery already nests whatever a scope adds
     * before ANDing it to the outer query, but which boolean it uses for that
     * AND is taken from the *first* clause the scope adds — so an ordering as
     * innocuous as `orWhere(...)->orWhereNull(...)` instead of this one would
     * silently reopen the leak with no clause removed at all. The explicit
     * closure makes the grouping unconditional instead of order-dependent.
     *
     * @param  Builder<Exercise>  $query
     */
    #[Scope]
    protected function availableTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user): void {
            $query->whereNull('user_id')->orWhere('user_id', $user->id);
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
