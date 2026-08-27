<?php

namespace App\Services\Companion;

/**
 * What Blob is, right now, for one user.
 *
 * Nothing here is stored — {@see CompanionResolver} rebuilds it from the record
 * on every read. It carries only what has already happened: there is no "next"
 * entry, no locked slot and no remaining count, because naming what is not yet
 * unlocked turns Blob into a checklist.
 */
final readonly class CompanionState
{
    /**
     * @param  int  $logCount  Outcomes recorded, whatever they were. A failure counts as much as a completion.
     * @param  int  $insightCount  Insight events recorded — concluded experiments, new versions, chain corrections, reflections.
     * @param  list<array{kind: string, name: string, variant: ?string, message: string, unlocked_at: ?string}>  $unlocks
     *                                                                                                                     Every ladder entry satisfied, in ladder order. The list is the history.
     */
    public function __construct(
        public int $logCount,
        public int $insightCount,
        public array $unlocks,
    ) {}

    /**
     * How far up the ladder this user has come: the number of entries satisfied.
     *
     * Derived from the unlock list rather than carried alongside it, so the two
     * cannot disagree. The MCP surfaces gate their companion line on this
     * changing — a line after every logged breakfast is wallpaper within a week.
     */
    public function stageIndex(): int
    {
        return count($this->unlocks);
    }

    /**
     * Blob's own body parts, in the order they arrived. Neither worn nor done,
     * so they are neither items nor abilities: `blob` is Blob existing at all.
     *
     * @return list<string>
     */
    public function features(): array
    {
        return $this->namesOfKind('body');
    }

    /**
     * What Blob wears. A repeated type with a `variant` is a recolour of
     * something it already owns, which is what happens once the four types
     * defined in config are spent.
     *
     * @return list<array{type: string, variant: ?string}>
     */
    public function items(): array
    {
        return array_values(array_map(
            static fn (array $unlock): array => [
                'type' => $unlock['name'],
                'variant' => $unlock['variant'],
            ],
            array_filter($this->unlocks, static fn (array $unlock): bool => $unlock['kind'] === 'item'),
        ));
    }

    /**
     * What Blob can do. Unbounded, and the more interesting of the two tracks.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        return $this->namesOfKind('ability');
    }

    /**
     * The most recently earned entry, or null before the first one.
     *
     * "Most recent" is the last satisfied entry in ladder order, which is also
     * the last in time: the ladder is walked in order and stops at the first
     * entry the record does not yet satisfy.
     *
     * @return array{kind: string, name: string, variant: ?string, message: string, unlocked_at: ?string}|null
     */
    public function latestUnlock(): ?array
    {
        return $this->unlocks === [] ? null : $this->unlocks[count($this->unlocks) - 1];
    }

    /**
     * @return array{
     *     log_count: int,
     *     insight_count: int,
     *     stage_index: int,
     *     features: list<string>,
     *     items: list<array{type: string, variant: ?string}>,
     *     abilities: list<string>,
     *     unlocks: list<array{kind: string, name: string, variant: ?string, message: string, unlocked_at: ?string}>,
     *     latest_unlock: array{kind: string, name: string, variant: ?string, message: string, unlocked_at: ?string}|null
     * }
     */
    public function toArray(): array
    {
        return [
            'log_count' => $this->logCount,
            'insight_count' => $this->insightCount,
            'stage_index' => $this->stageIndex(),
            'features' => $this->features(),
            'items' => $this->items(),
            'abilities' => $this->abilities(),
            'unlocks' => $this->unlocks,
            'latest_unlock' => $this->latestUnlock(),
        ];
    }

    /**
     * @return list<string>
     */
    private function namesOfKind(string $kind): array
    {
        return array_values(array_map(
            static fn (array $unlock): string => $unlock['name'],
            array_filter($this->unlocks, static fn (array $unlock): bool => $unlock['kind'] === $kind),
        ));
    }
}
