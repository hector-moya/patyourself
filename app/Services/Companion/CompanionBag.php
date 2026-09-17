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
     *     nodes: list<array{node: string, label: string, available: int, skill: string, met: bool, known: bool}>,
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
                // The world is there before anything has been chosen: an
                // account that has never touched this page still has two nodes
                // standing in its clearing, unmet and unusable.
                'nodes' => $this->worldBeforeAnythingHappened(),
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
            'nodes' => $this->nodes($companion, $met, $learned),
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
     * EVERY node in the world, whether Blob has met it or not.
     *
     * This is the one list here that is not filtered by what has happened, and
     * the exception is the point: both nodes are VISIBLE IN THE SCENE FROM THE
     * START (F1 §2). A node standing in the clearing is not a preview of
     * something you have not done — it is a thing that is there. What must stay
     * hidden is the SKILL, and that list is filtered.
     *
     * `met` says whether Blob has walked over and looked at it, which is what
     * revealed its skill. `known` says whether the skill was then bought.
     * Neither is a lock: clicking an unmet node is how the game starts.
     *
     * @param  list<string>  $met
     * @param  list<string>  $learned
     * @return list<array{node: string, label: string, available: int, skill: string, met: bool, known: bool}>
     */
    private function worldBeforeAnythingHappened(): array
    {
        /** @var array<string, array<string, mixed>> $authored */
        $authored = (array) config('companion.nodes', []);

        $listed = [];

        foreach ($authored as $name => $entry) {
            $listed[] = [
                'node' => $name,
                'label' => (string) $entry['label'],
                'available' => 0,
                'skill' => (string) $entry['skill'],
                'met' => false,
                'known' => false,
            ];
        }

        return $listed;
    }

    /**
     * @param  list<string>  $met
     * @param  list<string>  $learned
     * @return list<array{node: string, label: string, available: int, skill: string, met: bool, known: bool}>
     */
    private function nodes(Companion $companion, array $met, array $learned): array
    {
        /** @var array<string, array<string, mixed>> $authored */
        $authored = (array) config('companion.nodes', []);

        $standing = $companion->nodes->keyBy(
            static fn (CompanionNode $node): string => $node->node,
        );

        $listed = [];

        foreach ($authored as $name => $entry) {
            $listed[] = [
                'node' => $name,
                'label' => (string) $entry['label'],
                'available' => (int) ($standing->get($name)?->available ?? 0),
                'skill' => (string) $entry['skill'],
                'met' => in_array($name, $met, true),
                'known' => in_array($entry['skill'], $learned, true),
            ];
        }

        return $listed;
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
     * Every item whose existence Blob can account for.
     *
     * Seeded from the materials met nodes yield, then grown: an item is
     * knowable if it is one of those, or if it is a recipe every one of whose
     * ingredients is already knowable. Without the second half, nothing built
     * from something built could ever be listed — an axe made from rope would
     * be invisible for as long as rope is a thing you make rather than a thing
     * you find.
     *
     * A FIXED POINT RATHER THAN RECURSION, and that is the whole reason this is
     * shaped the way it is. `config` is authored data; an authored `a -> b -> a`
     * pair would send a recursive resolver into a loop on an ordinary page
     * load. Each pass adds whatever became reachable and the loop stops when a
     * pass adds nothing, which is at most one pass per catalogue entry.
     *
     * @param  list<string>  $met
     * @return list<string>
     */
    private function knowable(array $met): array
    {
        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        /** @var array<string, array<string, mixed>> $nodes */
        $nodes = (array) config('companion.nodes', []);

        $known = [];

        foreach ($met as $node) {
            if (isset($nodes[$node]['yields'])) {
                $known[(string) $nodes[$node]['yields']] = true;
            }
        }

        do {
            $added = false;

            foreach ($catalogue as $name => $item) {
                if (isset($known[$name])) {
                    continue;
                }

                /** @var array<string, int> $recipe */
                $recipe = (array) ($item['recipe'] ?? []);

                if ($recipe === [] || array_diff(array_keys($recipe), array_keys($known)) !== []) {
                    continue;
                }

                $known[$name] = true;
                $added = true;
            }
        } while ($added);

        return array_keys($known);
    }

    /**
     * Items that gate a node, and whether Blob has seen what they are for.
     *
     * A tool's recipe is knowable from its ingredients like any other, but
     * listing it before Blob has met anything that needs it would be a tool
     * with no reason attached. Meeting that node is what supplies the reason —
     * and it is the same encounter that puts the node's skill in the skill
     * list, so one click reveals both halves of what the node needs.
     *
     * Met at ANY node that names it is enough: seeing one reason is seeing a
     * reason. Stores nothing — this is a read of authored config against what
     * Blob has already met.
     *
     * @param  list<string>  $met
     * @return array<string, bool>
     */
    private function toolsAndTheirReasons(array $met): array
    {
        /** @var array<string, array<string, mixed>> $nodes */
        $nodes = (array) config('companion.nodes', []);

        $tools = [];

        foreach ($nodes as $name => $node) {
            $tool = (string) ($node['tool'] ?? '');

            if ($tool === '') {
                continue;
            }

            $tools[$tool] = ($tools[$tool] ?? false) || in_array($name, $met, true);
        }

        return $tools;
    }

    /**
     * Recipes Blob can account for, whether or not it is carrying the
     * ingredients yet. Knowable, not held: the point of a recipe is to tell you
     * what the thing in front of you is for.
     *
     * @param  list<string>  $met
     * @param  Collection<string, CompanionItem>  $held
     * @return list<array{item: string, label: string, recipe: array<string, int>, buildable: bool}>
     */
    private function recipes(array $met, Collection $held): array
    {
        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        $knowable = $this->knowable($met);
        $tools = $this->toolsAndTheirReasons($met);

        $listed = [];

        foreach ($catalogue as $name => $item) {
            /** @var array<string, int> $recipe */
            $recipe = (array) ($item['recipe'] ?? []);

            if ($recipe === [] || ! in_array($name, $knowable, true)) {
                continue;
            }

            // Knowable, but nothing Blob has seen needs it yet.
            if (($tools[$name] ?? true) === false) {
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
