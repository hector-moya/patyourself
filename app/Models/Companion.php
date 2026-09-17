<?php

namespace App\Models;

use App\Services\Companion\CompanionResolver;
use Database\Factories\CompanionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The part of Blob that is chosen rather than earned.
 *
 * Everything the record can say — what Blob is, what it wears, what it can do,
 * where it lives — is still derived by {@see CompanionResolver}
 * on every read. This row holds only the two things the record cannot know:
 * what Blob is called, and how much of its XP has been spent.
 *
 * `user_id` is deliberately absent from #[Fillable]. Create through the
 * relation — `$user->companion()->firstOrCreate([])` — which sets the key
 * itself, the same way {@see CompanionRemark} is written.
 *
 * A note for anyone editing `App\Services\Companion\*`: that namespace shares a
 * leaf name with this class, so an unqualified `Companion` resolves there to
 * `App\Services\Companion\Companion`, which does not exist. Those files need an
 * explicit `use App\Models\Companion;`, which then wins.
 */
#[Fillable([
    'name',
    'xp_spent',
])]
class Companion extends Model
{
    /** @use HasFactory<CompanionFactory> */
    use HasFactory;

    /** What Blob is called when nobody has named it. */
    public const DEFAULT_NAME = 'Blob';

    /**
     * Mirrors the `companions` migration's default so a freshly created row
     * already reads as zero instead of null until it is re-fetched. Same
     * reasoning as {@see User::$attributes}.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'xp_spent' => 0,
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CompanionSkill, $this> */
    public function skills(): HasMany
    {
        return $this->hasMany(CompanionSkill::class);
    }

    /** @return HasMany<CompanionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CompanionItem::class);
    }

    /** @return HasMany<CompanionNode, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(CompanionNode::class);
    }

    /**
     * What to call Blob in a sentence.
     *
     * Null — or whitespace, which is how a name gets cleared — reads as "Blob",
     * so an account that never renames sees exactly what it saw before the
     * column existed.
     */
    public function displayName(): string
    {
        $name = trim((string) $this->name);

        return $name === '' ? self::DEFAULT_NAME : $name;
    }

    /**
     * How much Blob can hold: its hands, plus every container it has built.
     *
     * Derived from the bag's contents and config's numbers rather than stored,
     * because a stored capacity would be a third thing that has to agree with
     * the other two.
     */
    public function capacity(): int
    {
        $base = (int) config('companion.capacity.base', 5);

        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        $added = $this->items->sum(
            static fn (CompanionItem $held): int => (int) ($catalogue[$held->item]['capacity'] ?? 0) * $held->quantity,
        );

        return $base + (int) $added;
    }

    /**
     * How much of that capacity is taken up.
     *
     * Only the categories config calls carried count. A container does not
     * occupy the space it creates, and a tool — when F2 adds one — is on the
     * belt rather than in the bag.
     */
    public function held(): int
    {
        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        /** @var list<string> $carried */
        $carried = (array) config('companion.capacity.carried', []);

        return (int) $this->items->sum(
            static fn (CompanionItem $held): int => in_array($catalogue[$held->item]['category'] ?? '', $carried, true)
                ? $held->quantity
                : 0,
        );
    }

    /**
     * What is left before the bag is full.
     *
     * Never negative: a container removed from config would otherwise make an
     * established bag owe space it cannot give back, and nothing here is ever
     * taken from Blob.
     */
    public function room(): int
    {
        return max(0, $this->capacity() - $this->held());
    }
}
