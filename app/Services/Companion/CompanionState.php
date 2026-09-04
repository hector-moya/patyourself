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
     * @param  list<array{kind: string, name: string, variant: ?string, room_object: ?string, message: string, unlocked_at: ?string}>  $unlocks
     *                                                                                                                                           Every ladder entry satisfied, in ladder order. The list is the history.
     * @param  string  $renderer  Which implementation draws Blob, from config.
     * @param  array<string, array{from: int, wall: string, window: string, light: string, dim: float|int}>  $room
     *                                                                                                              What each part of the day is drawn in, from config: the hour it starts at, the cabin's wall and window, and the light the whole scene is washed with — Blob included — at the strength `dim` names.
     * @param  list<array{name: string, trigger: string, at: int}>  $scenes  Ordered scene thresholds, from config.
     * @param  ?string  $sceneOverride  A scene to draw instead of the derived one, from the environment. Empty or null means the record decides.
     */
    public function __construct(
        public int $logCount,
        public int $insightCount,
        public array $unlocks,
        public string $renderer = 'svg',
        public array $room = [],
        public array $scenes = [],
        public ?string $sceneOverride = null,
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
     * What stands in Blob's room, in the order it arrived.
     *
     * Only what has been earned is ever listed, and the room draws only what is
     * listed. A room showing six grey outlines of what has not happened is a
     * task list wearing a rug.
     *
     * Deduplicated by name: the tail's own object pool overlaps the authored
     * ladder's on purpose (see config('companion.tail.room_objects')), so a
     * later rung can re-earn an object Blob already has. Handing the same
     * name to the view twice would both be a lie (it does not put a second
     * rug in the room) and a duplicate React key on the client.
     *
     * @return list<string>
     */
    public function roomObjects(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (array $unlock): ?string => $unlock['room_object'],
            $this->unlocks,
        ))));
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
     * Where Blob is, from the same counts the ladder walk already has.
     *
     * Walked in full rather than stopped at the first unsatisfied entry: a
     * scene threshold is independent of the others, so the record can clear
     * `cabin` without ever passing through an intermediate scene that does
     * not exist yet. The last entry the record has passed wins; a record
     * with nothing behind it gets the first entry.
     *
     * The override short-circuits all of that so the scene a record has not
     * reached can still be looked at. It is passed on as it stands rather
     * than checked against the thresholds below: those are when a scene
     * arrives, not the list of what can be drawn, and the client's `sceneFor`
     * is already the one place that answers the second question.
     */
    public function scene(): string
    {
        $override = $this->sceneOverride ?? '';

        if ($override !== '') {
            return $override;
        }

        /** @var list<array{name: string, trigger: string, at: int}> $scenes */
        $scenes = $this->scenes;

        // No entries is no first entry, so no name to hand over. Writing one
        // down here would be a third copy of whichever scene config lists
        // first today; the empty string is what `sceneFor()` already falls
        // back from, and the registry is where that name belongs.
        $current = $scenes === [] ? '' : (string) $scenes[0]['name'];

        foreach ($scenes as $entry) {
            $count = ($entry['trigger'] ?? 'logs') === 'insights' ? $this->insightCount : $this->logCount;

            if ($count >= (int) ($entry['at'] ?? 0)) {
                $current = (string) $entry['name'];
            }
        }

        return $current;
    }

    /**
     * @return array<string, mixed>
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
            'room_objects' => $this->roomObjects(),
            'unlocks' => $this->unlocks,
            'latest_unlock' => $this->latestUnlock(),
            // Carried with the state rather than passed as a second prop:
            // every screen that draws Blob needs both, and one of them going
            // missing would mean a screen that renders nothing.
            'renderer' => $this->renderer,
            'room' => $this->room,
            'scene' => $this->scene(),
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
