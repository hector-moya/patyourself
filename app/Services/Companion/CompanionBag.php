<?php

namespace App\Services\Companion;

use App\Actions\BuildItem;
use App\Actions\HarvestNode;
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
 * count of nodes that exist, no share of a whole and no "next". The payload is
 * where a total would first appear — before any pixel is drawn — so the shape
 * itself is guarded by CompanionBagTest, not just the rendering.
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
    public function __construct(
        private CompanionWallet $wallet,
        private CompanionResolver $resolver,
    ) {}

    /**
     * @return array{
     *     xp: int,
     *     capacity: int,
     *     held: int,
     *     name: string,
     *     items: list<array{item: string, label: string, category: string, quantity: int, droppable: bool}>,
     *     nodes: list<array{node: string, label: string, available: int, band: string, skill: string|null, met: bool, known: bool, usable: bool}>,
     *     skills: list<array{skill: string, label: string, price: int, known: bool, affordable: bool}>,
     *     recipes: list<array{item: string, label: string, recipe: array<string, int>, tool: string|null, buildable: bool}>,
     *     shelter: array{built: string|null, label: string|null, offer: array{stage: string, label: string, recipe: array<string, int>, buildable: bool}|null},
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
                // account that has never touched this page still has three
                // nodes standing in its clearing, unmet and unusable.
                'nodes' => $this->worldBeforeAnythingHappened(),
                'skills' => [],
                'recipes' => [],
                // Nothing built, and nothing offered: the offer waits on a
                // price Blob can account for, and an account that has met
                // nothing can account for nothing.
                'shelter' => ['built' => null, 'label' => null, 'offer' => null],
            ];
        }

        $met = $companion->nodes->pluck('node')->all();
        $learned = $companion->skills->pluck('name')->all();
        $held = $companion->items->keyBy(static fn (CompanionItem $item): string => $item->item);

        $knowable = $this->knowable($met, $held);

        return [
            'xp' => $balance,
            'capacity' => $companion->capacity(),
            'held' => $companion->held(),
            'name' => $companion->displayName(),
            'items' => $this->items($companion),
            'nodes' => $this->nodes($companion, $met, $learned, $held),
            'skills' => $this->skills($met, $learned, $balance),
            'recipes' => $this->recipes($knowable, $held, $companion),
            'shelter' => $this->shelter($companion, $held, $knowable, $user),
        ];
    }

    /**
     * Only what is actually held. An item config does not know is skipped
     * rather than shown as a blank row — the same rule the room already applies
     * to an unknown room object.
     *
     * @return list<array{item: string, label: string, category: string, quantity: int, droppable: bool}>
     */
    private function items(Companion $companion): array
    {
        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        /** @var list<string> $carried */
        $carried = (array) config('companion.capacity.carried', []);

        return array_values(array_filter(array_map(
            static function (CompanionItem $held) use ($catalogue, $carried): ?array {
                $entry = $catalogue[$held->item] ?? null;

                return $entry === null ? null : [
                    'item' => $held->item,
                    'label' => (string) $entry['label'],
                    'category' => (string) $entry['category'],
                    'quantity' => $held->quantity,
                    // Answered here rather than from the category on the
                    // client, so the list of what Blob carries has one author.
                    // A tool is on the belt and a container is the room
                    // itself; neither is a thing the bag can be relieved of.
                    'droppable' => in_array((string) $entry['category'], $carried, true),
                ];
            },
            $companion->items->all(),
        )));
    }

    /**
     * EVERY node in the world, whether Blob has met it or not.
     *
     * This is the one list here that is not filtered by what has happened, and
     * the exception is the point: all three nodes are VISIBLE IN THE SCENE FROM
     * THE START (F1 §2). A node standing in the clearing is not a preview of
     * something you have not done — it is a thing that is there. What must stay
     * hidden is the SKILL, and that list is filtered.
     *
     * `met` says whether Blob has walked over and looked at it, which is what
     * revealed its skill. `known` says whether the skill was then bought.
     * `usable` says whether the gesture would actually do something right now
     * — `known` AND either the node names no tool or that tool is held. The
     * two differ exactly when a node names a tool Blob is not carrying: the
     * trunk's `chop-wood` can be known well before its axe is built. Neither
     * `met` nor `known` is a lock: clicking an unmet node is how the game
     * starts.
     *
     * A heap needs a row to exist, and this branch is the case where there is
     * no companion row at all — so there can be none.
     *
     * @param  list<string>  $met
     * @param  list<string>  $learned
     * @return list<array{node: string, label: string, available: int, skill: string|null, met: bool, known: bool, usable: bool}>
     */
    private function worldBeforeAnythingHappened(): array
    {
        /** @var array<string, array<string, mixed>> $authored */
        $authored = (array) config('companion.nodes', []);

        $listed = [];

        foreach ($authored as $name => $entry) {
            if (! isset($entry['skill'])) {
                continue;
            }

            $listed[] = [
                'node' => $name,
                'label' => (string) $entry['label'],
                'available' => 0,
                'skill' => (string) $entry['skill'],
                'met' => false,
                'known' => false,
                // Nothing is known and nothing is held, so nothing here could
                // ever be usable yet.
                'usable' => false,
            ];
        }

        return $listed;
    }

    /**
     * A heap is absent until something puts it there. Every node of the WORLD
     * is listed whether met or not — a thing standing in the clearing is not
     * a preview of anything — but a heap is not part of the world, so listing
     * one would put a salvage pile in front of an account that never had a
     * cabin.
     *
     * @param  list<string>  $met
     * @param  list<string>  $learned
     * @param  Collection<string, CompanionItem>  $held
     * @return list<array{node: string, label: string, available: int, band: string, skill: string|null, met: bool, known: bool, usable: bool}>
     */
    private function nodes(Companion $companion, array $met, array $learned, Collection $held): array
    {
        /** @var array<string, array<string, mixed>> $authored */
        $authored = (array) config('companion.nodes', []);

        $standing = $companion->nodes->keyBy(
            static fn (CompanionNode $node): string => $node->node,
        );

        $listed = [];

        foreach ($authored as $name => $entry) {
            $skill = (string) ($entry['skill'] ?? '');
            $standingHere = $standing->get($name);

            if ($skill === '' && $standingHere === null) {
                continue;
            }

            $known = $skill === '' || in_array($skill, $learned, true);
            $tool = (string) ($entry['tool'] ?? '');

            $listed[] = [
                'node' => $name,
                'label' => (string) $entry['label'],
                'available' => (int) ($standingHere?->available ?? 0),
                'band' => $this->bandFor((int) ($standingHere?->available ?? 0)),
                // Null rather than '' so the client has one falsy case to
                // test, the same choice `recipes[].tool` already made.
                'skill' => $skill === '' ? null : $skill,
                'met' => $standingHere !== null || in_array($name, $met, true),
                'known' => $known,
                'usable' => $known && ($tool === '' || (int) ($held->get($tool)?->quantity ?? 0) > 0),
            ];
        }

        return $listed;
    }

    /**
     * Which band an amount falls in.
     *
     * Sorts by the floor and takes the last band that has begun — the same
     * shape `partOfDay()` uses, and the reason night wraps past midnight
     * without a fifth state describing 3am.
     *
     * An amount below every floor names NO band, rather than falling back to
     * the lowest. The client looks the name up in a sprite record and draws
     * nothing for a miss, which is the safe failure; guessing would draw a
     * full node for an amount no author described.
     */
    private function bandFor(int $available): string
    {
        /** @var array<string, int> $bands */
        $bands = (array) config('companion.node_bands', []);

        $named = collect($bands)
            ->sortBy(static fn (int $floor): int => $floor)
            ->filter(static fn (int $floor): bool => $floor <= $available)
            ->keys()
            ->last();

        return is_string($named) ? $named : '';
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
     * Every item whose existence Blob can account for, and — because the two
     * are the same rule now — every item whose gates Blob has cleared.
     *
     * Seeded from the materials met nodes yield, then grown: an item is
     * knowable if it is one of those, or if it is a recipe every one of whose
     * ingredients is already knowable, whose own named tool (if it has one) is
     * itself already knowable, and which no node is still withholding as THAT
     * node's tool. The last two are separate checks — the axe names no tool of
     * its own and is still withheld until the trunk is met, so this is never
     * only about what a recipe explicitly names. Without the ingredient half,
     * nothing built from something built could ever be listed — an axe made
     * from rope would be invisible for as long as rope is a thing you make
     * rather than a thing you find. Without either tool half, a thing built
     * from a gated tool would still slip through: the old gate stopped only
     * the tool itself, never what is made from it.
     *
     * A FIXED POINT RATHER THAN RECURSION, and that is the whole reason this is
     * shaped the way it is. `config` is authored data; an authored `a -> b -> a`
     * pair would send a recursive resolver into a loop on an ordinary page
     * load. Each pass adds whatever became reachable and the loop stops when a
     * pass adds nothing, which is at most one pass per catalogue entry. A gate
     * changes what a pass is allowed to add, never how many passes there are,
     * so the loop still terminates for the same reason it always did.
     *
     * SEEDED FROM WHAT IS HELD, AS WELL AS FROM MET NODES' YIELDS. A heap is
     * removed from `companion_nodes` the instant it drains — see
     * {@see HarvestNode} — so a converted account whose only met
     * node was the heap loses that node from `$met` at the exact moment it
     * finishes gathering. Without this seed, the last plank out of the heap
     * would also take `planks` out of `knowable`, withdrawing the shelter
     * offer the player just finished paying for. A thing Blob is actually
     * carrying is self-evidently a thing Blob can account for, whether or not
     * anything that yields it still stands in the clearing.
     *
     * @param  list<string>  $met
     * @param  Collection<string, CompanionItem>  $held
     * @return list<string>
     */
    private function knowable(array $met, Collection $held): array
    {
        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        /** @var array<string, array<string, mixed>> $nodes */
        $nodes = (array) config('companion.nodes', []);

        $tools = $this->toolsAndTheirReasons($met);

        $known = [];

        foreach ($met as $node) {
            if (isset($nodes[$node]['yields'])) {
                $known[(string) $nodes[$node]['yields']] = true;
            }
        }

        foreach ($held->keys() as $item) {
            $known[(string) $item] = true;
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

                // A tool nothing has shown Blob blocks the thing it makes —
                // and because that block happens INSIDE the fixed point, it
                // also blocks whatever is made from that in turn. A guard
                // sitting outside this loop can only ever stop the first link
                // of a chain.
                $tool = (string) ($item['tool'] ?? '');

                if ($tool !== '' && ! isset($known[$tool])) {
                    continue;
                }

                // Something a node gates is not accounted for until that node
                // has been met, for the same reason and with the same reach.
                if (($tools[$name] ?? true) === false) {
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
     * Consumed inside {@see knowable()}'s fixed point now, rather than applied
     * only while a recipe row is being built — which is what gives it reach.
     * A gate checked at render time can only ever stop the withheld tool from
     * being listed itself; checked inside the loop that decides what `$known`
     * contains, a tool this map still withholds never enters `$known` at all,
     * so nothing built from it becomes accountable either — named as a
     * recipe's own tool, taken as a plain ingredient, or nested any number of
     * links deeper. It rides inside the same ingredient loop that already
     * gave chains their reach, rather than standing beside it.
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
     * `buildable` asks the same question {@see BuildItem} asks
     * before it writes anything: the tool held, the ingredients held, AND
     * the net effect on carried room actually fitting — `HarvestNode` fills
     * the bag by design, so a recipe can be fully affordable and still not
     * fit. `Companion::wouldFit()` is the one place that arithmetic lives, so
     * this can never quietly promise a build that BuildItem is about to
     * refuse.
     *
     * LISTED ONLY WHEN ITS INGREDIENTS AND TOOL ARE THEMSELVES KNOWABLE, NOT
     * JUST ITS OWN ITEM. For anything that entered `knowable()` through the
     * RECIPE path this is a no-op by construction — the fixed point only ever
     * adds an item once every ingredient (and named tool) is already known,
     * so the check below always passes. It matters only for the SEED path:
     * `knowable()` also seeds directly from held items, so a material that is
     * both a node's yield and a recipe's output — `planks` is the first one —
     * can be knowable purely because Blob is carrying it, with neither its
     * ingredient nor its tool ever having been shown. Without this check a
     * converted account would see "planks — 1 timber, handsaw" having met no
     * node and seen neither.
     *
     * @param  list<string>  $knowable
     * @param  Collection<string, CompanionItem>  $held
     * @return list<array{item: string, label: string, recipe: array<string, int>, tool: string|null, buildable: bool}>
     */
    private function recipes(array $knowable, Collection $held, Companion $companion): array
    {
        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        $listed = [];

        foreach ($catalogue as $name => $item) {
            /** @var array<string, int> $recipe */
            $recipe = (array) ($item['recipe'] ?? []);

            if ($recipe === [] || ! in_array($name, $knowable, true)) {
                continue;
            }

            $tool = (string) ($item['tool'] ?? '');

            if (array_diff(array_keys($recipe), $knowable) !== []
                || ($tool !== '' && ! in_array($tool, $knowable, true))) {
                continue;
            }

            // Held, not consumed. A tool is used and never used up, so this
            // asks whether it is in the bag and takes nothing from it.
            $buildable = $tool === '' || (int) ($held->get($tool)?->quantity ?? 0) > 0;

            if ($buildable) {
                foreach ($recipe as $ingredient => $needed) {
                    if ((int) ($held->get($ingredient)?->quantity ?? 0) < $needed) {
                        $buildable = false;

                        break;
                    }
                }
            }

            // Held and affordable is not the same as fitting: a build whose
            // net effect on carried room overflows the bag is still a
            // refusal, and a full bag is the expected outcome of harvesting,
            // not an edge case.
            if ($buildable) {
                $makes = max(1, (int) ($item['makes'] ?? 1));

                $buildable = $companion->wouldFit($name, $recipe, $makes);
            }

            $listed[] = [
                'item' => $name,
                'label' => (string) $item['label'],
                'recipe' => array_map(static fn ($count): int => (int) $count, $recipe),
                // Null rather than '' so the client has one falsy case to test
                // and never has to tell an absent tool from an empty one.
                'tool' => $tool === '' ? null : $tool,
                'buildable' => $buildable,
            ];
        }

        return $listed;
    }

    /**
     * What is standing in the clearing, and the ONE stage that can be chosen
     * next.
     *
     * Only the next stage is ever listed, and a stage whose predecessor does
     * not exist is ABSENT rather than greyed — the same rule the skill list has
     * followed since F1. A player at the hut with three insights sees no
     * shelter row at all, and the feature answers that with silence: naming
     * what they are waiting for would be the app stating a plan.
     *
     * Two things withhold the offer, and they are different in kind. A price in
     * a material Blob has never been shown is a price quoted in a currency
     * nobody has seen, so the offer waits on `knowable()` exactly as a recipe
     * does. A floor on the record is not a price at all — it cannot be paid
     * from the bag — so listing the cabin at "14 planks" while the real
     * obstacle is the record would be a lie about what the thing costs.
     *
     * `offer`, not `next`. The payload-shape guard bans `next` outright, and it
     * is right to: a "next" is the first half of a checklist.
     *
     * @param  Collection<string, CompanionItem>  $held
     * @param  list<string>  $knowable
     * @return array{built: string|null, label: string|null, offer: array{stage: string, label: string, recipe: array<string, int>, buildable: bool}|null}
     */
    private function shelter(Companion $companion, Collection $held, array $knowable, User $user): array
    {
        /** @var array<string, array<string, mixed>> $stages */
        $stages = (array) config('companion.shelter', []);

        $order = array_keys($stages);
        $built = $companion->shelter;

        $standing = $built === null ? null : ($stages[$built] ?? null);

        // A stage config no longer knows still stands: nothing about Blob is
        // ever taken because an author edited a list. Nothing follows it,
        // because there is no position in the arc to follow from.
        if ($built !== null && $standing === null) {
            return ['built' => $built, 'label' => $built, 'offer' => null];
        }

        $at = $built === null ? -1 : (int) array_search($built, $order, true);
        $stage = $order[$at + 1] ?? null;

        $offer = null;

        if ($stage !== null) {
            $entry = $stages[$stage];

            /** @var array<string, int> $recipe */
            $recipe = (array) ($entry['recipe'] ?? []);
            $floor = (int) ($entry['insights'] ?? 0);

            $accountable = array_diff(array_keys($recipe), $knowable) === [];

            // Short-circuited on purpose: only a stage that names a floor pays
            // for the four queries that answer it.
            if ($accountable && ($floor === 0 || count($this->resolver->insightMoments($user)) >= $floor)) {
                $buildable = true;

                foreach ($recipe as $ingredient => $needed) {
                    if ((int) ($held->get($ingredient)?->quantity ?? 0) < $needed) {
                        $buildable = false;

                        break;
                    }
                }

                $offer = [
                    'stage' => $stage,
                    'label' => (string) $entry['label'],
                    'recipe' => array_map(static fn ($count): int => (int) $count, $recipe),
                    'buildable' => $buildable,
                ];
            }
        }

        return [
            'built' => $built,
            'label' => $standing === null ? null : (string) $standing['label'],
            'offer' => $offer,
        ];
    }
}
