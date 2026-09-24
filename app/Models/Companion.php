<?php

namespace App\Models;

use App\Actions\BuildItem;
use App\Services\Companion\CompanionBag;
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
    // Only ever written by BuildShelter, and only ever forward. `salvaged_at`
    // is deliberately absent: it is written once, by the conversion, through
    // forceFill — it is not a thing any request should be able to set.
    'shelter',
    'woodpile',
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
        'woodpile' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Immutable: written exactly once, by SalvageTheCabin, through
            // forceFill, and never mutated after — the same write-once cast
            // CompanionSkill::learned_at uses.
            'salvaged_at' => 'immutable_datetime',
        ];
    }

    /**
     * What this user calls their companion, or "Blob" when they never said.
     *
     * A query rather than `$user->companion?->displayName()`, and that is the
     * whole reason this exists as a static. Eloquent caches a lazily-loaded
     * relation on the model instance, NULL INCLUDED — so a user object asked
     * for its companion before the row existed answers null for the rest of the
     * request, and a deliberately renamed companion renders as "Blob" on the
     * very request that named it.
     *
     * Three callers had written this out separately before it moved here.
     */
    public static function nameFor(User $user): string
    {
        $name = trim((string) static::query()
            ->where('user_id', $user->id)
            ->value('name'));

        return $name === '' ? self::DEFAULT_NAME : $name;
    }

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

    /**
     * What is at home rather than on Blob.
     *
     * A SECOND RELATION, never a filter on `items`. Everything that reads
     * `items` — `held()`, `capacity()`, `wouldFit()`, `spend()`,
     * `shortfallFor()` — means "what Blob is carrying", and it means that
     * because of this separation rather than because each one remembered to
     * ask. Nothing in this class may ever sum the two together.
     *
     * @return HasMany<CompanionStashItem, $this>
     */
    public function stashItems(): HasMany
    {
        return $this->hasMany(CompanionStashItem::class);
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
            static function (CompanionItem $held) use ($catalogue): int {
                // A CONTAINER, and nothing else. This summed every held item's
                // `capacity` field regardless of category, so any future
                // structure or tool that gained one would have enlarged the bag
                // forever — a permanent thing quietly changing what Blob can
                // carry, which is the mirror image of the rule that a tool
                // never occupies a slot.
                if (($catalogue[$held->item]['category'] ?? '') !== 'container') {
                    return 0;
                }

                return (int) ($catalogue[$held->item]['capacity'] ?? 0) * $held->quantity;
            },
        );

        return $base + (int) $added;
    }

    /**
     * How much of that capacity is taken up.
     *
     * Only the categories config calls carried count. A container does not
     * occupy the space it creates, and a tool is on the belt rather than in
     * the bag.
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

    /**
     * Whether building `$item` — from `$recipe`, making `$makes` of it —
     * would fit in what is left of the bag.
     *
     * `held − consumed + made ≤ capacity`, counting only the categories
     * `companion.capacity.carried` names, on both sides — held, not
     * consumed, because a tool named in a recipe is used and never used up.
     *
     * This is the exact arithmetic {@see BuildItem} enforces
     * before it writes anything, pulled out here so a reader deciding
     * whether a build WOULD succeed — {@see CompanionBag::recipes()} —
     * cannot silently drift from what actually happens when it is tried.
     * BuildItem remains the one place that refuses; this only answers the
     * same question ahead of time.
     *
     * @param  array<string, int>  $recipe
     */
    public function wouldFit(string $item, array $recipe, int $makes): bool
    {
        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        /** @var list<string> $carried */
        $carried = (array) config('companion.capacity.carried', []);

        $categoryOf = static fn (string $name): string => (string) ($catalogue[$name]['category'] ?? '');

        $consumed = 0;

        foreach ($recipe as $ingredient => $needed) {
            if (in_array($categoryOf($ingredient), $carried, true)) {
                $consumed += $needed;
            }
        }

        $made = in_array($categoryOf($item), $carried, true) ? $makes : 0;

        return $this->held() - $consumed + $made <= $this->capacity();
    }

    /**
     * What a recipe is still short of: ingredient to how many more are needed.
     *
     * Empty means affordable. Everything missing rather than the first thing,
     * so a recipe short on two ingredients takes one look rather than two
     * attempts.
     *
     * Here rather than in the action for the same reason {@see wouldFit()} is:
     * a shelter stage consumes materials exactly as a recipe does, and two
     * copies of this loop would eventually disagree about what an emptied
     * stack becomes.
     *
     * @param  array<string, int>  $recipe
     * @return array<string, int>
     */
    public function shortfallFor(array $recipe): array
    {
        $stacks = $this->items()
            ->whereIn('item', array_keys($recipe))
            ->get()
            ->keyBy(static fn (CompanionItem $stack): string => $stack->item);

        $missing = [];

        foreach ($recipe as $ingredient => $needed) {
            $have = (int) ($stacks->get($ingredient)?->quantity ?? 0);

            if ($have < $needed) {
                $missing[$ingredient] = $needed - $have;
            }
        }

        return $missing;
    }

    /**
     * Spends a recipe. Call only once {@see shortfallFor()} has come back
     * empty — this assumes every ingredient is there, because a partial spend
     * would be the first thing in this feature that ever took something away
     * without giving anything back.
     *
     * A stack spent to nothing is REMOVED rather than left at zero: an empty
     * row would render as a line in the bag saying Blob is carrying no fibre,
     * which is not a thing worth saying.
     *
     * @param  array<string, int>  $recipe
     */
    public function spend(array $recipe): void
    {
        $stacks = $this->items()
            ->whereIn('item', array_keys($recipe))
            ->get()
            ->keyBy(static fn (CompanionItem $stack): string => $stack->item);

        foreach ($recipe as $ingredient => $needed) {
            /** @var CompanionItem $stack */
            $stack = $stacks->get($ingredient);

            if ($stack->quantity === $needed) {
                $stack->delete();

                continue;
            }

            $stack->decrement('quantity', $needed);
        }
    }
}
