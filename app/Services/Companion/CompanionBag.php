<?php

namespace App\Services\Companion;

use App\Models\Companion;
use App\Models\CompanionItem;
use App\Models\CompanionNode;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What the bag is, for one user, in one shape.
 *
 * One place answers "what does the screen need", so the controller does not
 * assemble it and neither does the page. The same reason
 * {@see CompanionState} exists for the gift track.
 *
 * WHAT IS ABSENT HERE IS THE DESIGN. There is no count of skills that exist, no
 * count of nodes that exist, no percentage and no "next". The payload is where
 * a total would first appear — before any pixel is drawn — so the shape itself
 * is guarded by CompanionBagTest, not just the rendering.
 *
 * Two lists are filtered by what Blob has MET rather than by what is authored:
 *
 *   - `skills` holds only skills whose node has been encountered, which is what
 *     makes the list a menu that grows rather than a checklist with most of it
 *     greyed out. (F1 §2)
 *   - `recipes` holds only recipes whose ingredients have been met, because
 *     knowing a basket exists before knowing what reeds are is a preview of
 *     something that has not happened.
 *
 * A skill stays on the list once learned, marked `known`, rather than being
 * removed. The list is what Blob has met, and meeting it is what happened.
 *
 * Purely a read: looking at the bag never writes a companion row.
 */
final readonly class CompanionBag
{
    public function __construct(private CompanionWallet $wallet) {}

    /**
     * @return array{
     *     xp: int,
     *     capacity: int,
     *     held: int,
     *     name: string,
     *     items: list<array{item: string, label: string, category: string, quantity: int}>,
     *     nodes: list<array{node: string, label: string, available: int, skill: string, known: bool}>,
     *     skills: list<array{skill: string, label: string, price: int, known: bool, affordable: bool}>,
     *     recipes: list<array{item: string, label: string, recipe: array<string, int>, buildable: bool}>,
     * }
     */
    public function forUser(User $user): array
    {
        $companion = Companion::query()
            ->with(['items', 'nodes', 'skills'])
            ->where('user_id', $user->id)
            ->first();

        $balance = $this->wallet->balanceFor($user);

        // Nothing chosen yet. Every list is empty rather than absent, so the
        // screen never has to ask whether a key exists — and no row is written
        // just by looking at the bag.
        if (! $companion instanceof Companion) {
            return [
                'xp' => $balance,
                'capacity' => (int) config('companion.capacity.base', 5),
                'held' => 0,
                'name' => Companion::DEFAULT_NAME,
                'items' => [],
                'nodes' => [],
                'skills' => [],
                'recipes' => [],
            ];
        }

        $met = $companion->nodes->pluck('node')->all();
        $learned = $companion->skills->pluck('name')->all();
        $held = $companion->items->keyBy(static fn (CompanionItem $item): string => $item->item);

        return [
            'xp' => $balance,
            'capacity' => $companion->capacity(),
            'held' => $companion->held(),
            'name' => $companion->displayName(),
            'items' => $this->items($companion),
            'nodes' => $this->nodes($companion, $learned),
            'skills' => $this->skills($met, $learned, $balance),
            'recipes' => $this->recipes($met, $held),
        ];
    }

    /**
     * Only what is actually held. An item config does not know is skipped
     * rather than shown as a blank row — the same rule the room already applies
     * to an unknown room object.
     *
     * @return list<array{item: string, label: string, category: string, quantity: int}>
     */
    private function items(Companion $companion): array
    {
        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        return array_values(array_filter(array_map(
            static function (CompanionItem $held) use ($catalogue): ?array {
                $entry = $catalogue[$held->item] ?? null;

                return $entry === null ? null : [
                    'item' => $held->item,
                    'label' => (string) $entry['label'],
                    'category' => (string) $entry['category'],
                    'quantity' => $held->quantity,
                ];
            },
            $companion->items->all(),
        )));
    }

    /**
     * The nodes Blob has met, and what is standing at each.
     *
     * @param  list<string>  $learned
     * @return list<array{node: string, label: string, available: int, skill: string, known: bool}>
     */
    private function nodes(Companion $companion, array $learned): array
    {
        /** @var array<string, array<string, mixed>> $authored */
        $authored = (array) config('companion.nodes', []);

        return array_values(array_filter(array_map(
            static function (CompanionNode $node) use ($authored, $learned): ?array {
                $entry = $authored[$node->node] ?? null;

                return $entry === null ? null : [
                    'node' => $node->node,
                    'label' => (string) $entry['label'],
                    'available' => $node->available,
                    'skill' => (string) $entry['skill'],
                    'known' => in_array($entry['skill'], $learned, true),
                ];
            },
            $companion->nodes->all(),
        )));
    }

    /**
     * Skills whose node has been met — no more, and never fewer once met.
     *
     * `affordable` rather than a disabled row: a price you cannot pay yet is a
     * menu, and the surface disables the button while leaving the row readable.
     *
     * @param  list<string>  $met
     * @param  list<string>  $learned
     * @return list<array{skill: string, label: string, price: int, known: bool, affordable: bool}>
     */
    private function skills(array $met, array $learned, int $balance): array
    {
        /** @var array<string, array{price: int, node: string, label: string}> $authored */
        $authored = (array) config('companion.skills', []);

        $listed = [];

        foreach ($authored as $name => $skill) {
            if (! in_array($skill['node'], $met, true)) {
                continue;
            }

            $price = (int) $skill['price'];

            $listed[] = [
                'skill' => $name,
                'label' => (string) $skill['label'],
                'price' => $price,
                'known' => in_array($name, $learned, true),
                'affordable' => $balance >= $price,
            ];
        }

        return $listed;
    }

    /**
     * Recipes whose ingredients Blob has met, whether or not it is carrying
     * them yet. Met, not held: the point of a recipe is to tell you what the
     * thing in front of you is for.
     *
     * @param  list<string>  $met
     * @param  Collection<string, CompanionItem>  $held
     * @return list<array{item: string, label: string, recipe: array<string, int>, buildable: bool}>
     */
    private function recipes(array $met, Collection $held): array
    {
        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        /** @var array<string, array<string, mixed>> $nodes */
        $nodes = (array) config('companion.nodes', []);

        $metMaterials = [];

        foreach ($met as $node) {
            if (isset($nodes[$node]['yields'])) {
                $metMaterials[] = (string) $nodes[$node]['yields'];
            }
        }

        $listed = [];

        foreach ($catalogue as $name => $item) {
            /** @var array<string, int> $recipe */
            $recipe = (array) ($item['recipe'] ?? []);

            if ($recipe === []) {
                continue;
            }

            // Every ingredient has to be something Blob has met. A recipe half
            // in the dark would name a material the user has no way to place.
            $knowable = array_diff(array_keys($recipe), $metMaterials) === [];

            if (! $knowable) {
                continue;
            }

            $buildable = true;

            foreach ($recipe as $ingredient => $needed) {
                if ((int) ($held->get($ingredient)?->quantity ?? 0) < $needed) {
                    $buildable = false;

                    break;
                }
            }

            $listed[] = [
                'item' => $name,
                'label' => (string) $item['label'],
                'recipe' => array_map(static fn ($count): int => (int) $count, $recipe),
                'buildable' => $buildable,
            ];
        }

        return $listed;
    }
}
