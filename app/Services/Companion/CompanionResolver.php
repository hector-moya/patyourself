<?php

namespace App\Services\Companion;

use App\Actions\UpdateIntention;
use App\Models\Companion;
use App\Models\Strategy;
use App\Models\Summary;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Works out what Blob is from the record, on every read.
 *
 * A pure read over tables the app already keeps. There is no companion table,
 * no counter and no migration, so there is nothing to backfill, nothing to
 * repair and nothing that can silently drift out of step with the record it
 * claims to describe.
 *
 * KNOWN EDGE CASE, deliberately unsolved: deleting an outcome lowers logCount,
 * which could take an item back off Blob — the one way this feature can regress,
 * and it needs a user to delete history to happen. If it ever becomes real,
 * clamp with a single `companion_high_water` integer on users. Not before: a
 * stored high-water mark is exactly the thing this class exists to avoid.
 */
final readonly class CompanionResolver
{
    /** An experiment reached a verdict — any verdict. */
    public const INSIGHT_CONCLUDED_EXPERIMENT = 'concluded-experiment';

    /** A new strategy version was started from an existing one. */
    public const INSIGHT_STARTED_EXPERIMENT = 'started-experiment';

    /** A loop's cue / craving / response / reward was corrected. */
    public const INSIGHT_CHAIN_CORRECTION = 'chain-correction';

    /** A reflection was written on a loop. */
    public const INSIGHT_REFLECTION = 'reflection';

    /** @var list<string> */
    public const INSIGHT_KINDS = [
        self::INSIGHT_CONCLUDED_EXPERIMENT,
        self::INSIGHT_STARTED_EXPERIMENT,
        self::INSIGHT_CHAIN_CORRECTION,
        self::INSIGHT_REFLECTION,
    ];

    /**
     * Walks the ladder in order and stops at the first entry the record does not
     * yet satisfy.
     *
     * In order, rather than filtering for every satisfied entry: the ladder
     * alternates ability -> item on purpose, and a gap would hand out a `walk`
     * to a Blob that has not appeared yet.
     */
    public function forUser(User $user): CompanionState
    {
        $logs = $this->logMoments($user);
        $insights = $this->insightMoments($user);
        $name = $this->nameFor($user);

        $unlocks = [];

        /** @var array<int, array<string, mixed>> $ladder */
        $ladder = config('companion.ladder', []);

        foreach ($ladder as $entry) {
            $moments = ($entry['trigger'] ?? 'logs') === 'insights' ? $insights : $logs;
            $at = (int) ($entry['at'] ?? 1);

            if (count($moments) < $at) {
                break;
            }

            $unlocks[] = [
                'kind' => (string) $entry['kind'],
                'name' => (string) $entry['name'],
                'variant' => $entry['variant'] ?? null,
                // What this unlock put in the room, if anything. Null for most
                // entries: the room fills up slowly on purpose.
                'room_object' => $entry['roomObject'] ?? null,
                // Substituted here, once, rather than at each surface. The
                // coach relays this string verbatim through
                // CompanionAnnouncement and the screen prints it as it stands,
                // so a token reaching either of them is a token the reader
                // sees.
                'message' => str_replace('{name}', $name, (string) $entry['message']),
                // When this one arrived: the moment of the trigger that earned
                // it, not of the request that noticed it. Reached through a
                // helper because the two streams are shaped differently now —
                // logs are bare moments, insights carry their kind.
                'unlocked_at' => $this->momentAt($moments, $at)->toIso8601String(),
            ];
        }

        // The tail begins only once the authored ladder is finished. A partial
        // walk means the record has not reached the end yet, and the tail is
        // what happens after the end.
        if (count($unlocks) === count($ladder)) {
            $unlocks = [...$unlocks, ...$this->tailUnlocks($insights, $name)];
        }

        return new CompanionState(
            count($logs),
            count($insights),
            $unlocks,
            (string) config('companion.renderer', 'svg'),
            (array) config('companion.room', []),
            (array) config('companion.scenes', []),
            // Read, never written: the override says which scene to draw and
            // nothing about what the record has earned, so this stays as much
            // of a pure read as the line above it.
            (string) config('companion.scene_override', ''),
            $name,
        );
    }

    /**
     * What this user calls their companion, or "Blob" when they never said.
     *
     * Queried rather than read off `$user->companion`: Eloquent caches a
     * lazily-loaded relation on the model instance, null included, so a user
     * object asked for its companion before the row existed answers null for
     * the rest of the request. Here that would render an established,
     * deliberately renamed companion as "Blob" on the request that named it.
     */
    private function nameFor(User $user): string
    {
        $name = trim((string) Companion::query()
            ->where('user_id', $user->id)
            ->value('name'));

        return $name === '' ? Companion::DEFAULT_NAME : $name;
    }

    /**
     * Every outcome this user has recorded, oldest first.
     *
     * All three outcomes count, and count equally. A `failed` outcome advances
     * Blob exactly as far as a `completed` one because the thing being rewarded
     * is the record being kept honestly, not the habit going well.
     *
     * Dated by `logged_at` — when the user sat down and told the truth — rather
     * than by the occasion, so a catch-up session earns its unlocks at the
     * moment it happens instead of retroactively.
     *
     * Public because {@see CompanionWallet} prices the same stream the ladder
     * counts, and two readers deriving the record separately is how they come
     * to disagree about what happened.
     *
     * @return list<CarbonImmutable>
     */
    public function logMoments(User $user): array
    {
        return $user->actionLogs()
            ->orderBy('logged_at')
            ->orderBy('id')
            ->pluck('logged_at')
            ->map(static fn ($moment): CarbonImmutable => CarbonImmutable::instance($moment))
            ->all();
    }

    /**
     * Every insight event this user has recorded, oldest first, each tagged
     * with which kind it was.
     *
     * Four sources, all of them existing records rather than a judgement call:
     * an experiment concluded, a new version started, the loop's chain
     * corrected, a reflection written. Merged and re-sorted because a ladder
     * entry needs the Nth insight overall, not the Nth of one kind.
     *
     * The ladder only ever needed `count()`, which is why the kind used to be
     * discarded here. The XP table prices each source differently (F1 §3), so
     * it is carried now — tagged once, in this method, rather than four times
     * in the collectors below.
     *
     * Public for the same reason {@see logMoments()} is.
     *
     * @return list<array{at: CarbonImmutable, kind: string}>
     */
    public function insightMoments(User $user): array
    {
        $moments = [
            ...$this->tag($this->concludedExperiments($user), self::INSIGHT_CONCLUDED_EXPERIMENT),
            ...$this->tag($this->startedExperiments($user), self::INSIGHT_STARTED_EXPERIMENT),
            ...$this->tag($this->chainCorrections($user), self::INSIGHT_CHAIN_CORRECTION),
            ...$this->tag($this->reflections($user), self::INSIGHT_REFLECTION),
        ];

        // By timestamp rather than by object: two moments in the same second
        // from different sources still need a stable, total order. usort has
        // been stable since PHP 8.0, so equal timestamps keep the source order
        // above — which is what CompanionLadderPinTest asserts.
        usort(
            $moments,
            static fn (array $a, array $b): int => $a['at']->getTimestamp() <=> $b['at']->getTimestamp(),
        );

        return $moments;
    }

    /**
     * @param  list<CarbonImmutable>  $moments
     * @return list<array{at: CarbonImmutable, kind: string}>
     */
    private function tag(array $moments, string $kind): array
    {
        return array_map(
            static fn (CarbonImmutable $at): array => ['at' => $at, 'kind' => $kind],
            $moments,
        );
    }

    /**
     * The Nth moment of a trigger stream, whichever of the two shapes it is.
     *
     * @param  list<CarbonImmutable>|list<array{at: CarbonImmutable, kind: string}>  $moments
     */
    private function momentAt(array $moments, int $at): CarbonImmutable
    {
        $moment = $moments[$at - 1];

        return $moment instanceof CarbonImmutable ? $moment : $moment['at'];
    }

    /**
     * Experiments that reached a verdict — any verdict. `failed` and
     * `inconclusive` are conclusions too, and reaching one is the insight.
     *
     * Dated by `updated_at`, because concluding writes a verdict and there is no
     * `concluded_at` column to read. Close enough for a history list: the only
     * way it drifts is a later write to the same row, and every write that
     * follows a conclusion is itself an insight event sitting beside it.
     *
     * @return list<CarbonImmutable>
     */
    private function concludedExperiments(User $user): array
    {
        return $this->strategiesOf($user)
            ->whereNotNull('verdict')
            ->pluck('updated_at')
            ->map(static fn ($moment): CarbonImmutable => CarbonImmutable::instance($moment))
            ->all();
    }

    /**
     * Versions started via start-experiment. Identified by `parent_strategy_id`,
     * which only that flow sets — a loop's first version has no parent and is
     * not an insight, it is the loop being created.
     *
     * @return list<CarbonImmutable>
     */
    private function startedExperiments(User $user): array
    {
        return $this->strategiesOf($user)
            ->whereNotNull('parent_strategy_id')
            ->pluck('created_at')
            ->map(static fn ($moment): CarbonImmutable => CarbonImmutable::instance($moment))
            ->all();
    }

    /**
     * Corrections to a loop's cue / craving / response / reward.
     *
     * Read from `intentions.metadata.chain_revisions`, which
     * {@see UpdateIntention} appends to. The chain is corrected in
     * place — that is the point, the loop describes one behaviour and there is
     * only ever one current description — so without that trace the correction
     * leaves no record at all, and an insight the app cannot see cannot count.
     *
     * @return list<CarbonImmutable>
     */
    private function chainCorrections(User $user): array
    {
        $moments = [];

        /** @var list<array<string, mixed>|null> $metadata */
        $metadata = $user->intentions()->pluck('metadata')->all();

        foreach ($metadata as $loop) {
            foreach ($loop['chain_revisions'] ?? [] as $revision) {
                if (isset($revision['at'])) {
                    $moments[] = CarbonImmutable::parse($revision['at']);
                }
            }
        }

        return $moments;
    }

    /**
     * Reflections written on a loop. Append-only, so every one is its own event.
     *
     * @return list<CarbonImmutable>
     */
    private function reflections(User $user): array
    {
        return $user->summaries()
            ->where('scope', Summary::SCOPE_INTENTION)
            ->pluck('created_at')
            ->map(static fn ($moment): CarbonImmutable => CarbonImmutable::instance($moment))
            ->all();
    }

    /**
     * @return Builder<Strategy>
     */
    private function strategiesOf(User $user): Builder
    {
        return Strategy::query()
            ->whereHas('intention', static fn (Builder $query) => $query->where('user_id', $user->id));
    }

    /**
     * Rungs past the last authored one, recolouring what Blob already owns.
     *
     * Every value is derived from the rung's index — which type, which variant,
     * which message, whether a room object comes with it. Nothing is random,
     * because a rung is history the moment it is earned and history cannot
     * reword itself between two reads of the same record.
     *
     * @param  list<array{at: CarbonImmutable, kind: string}>  $insights
     * @return list<array<string, mixed>>
     */
    private function tailUnlocks(array $insights, string $name): array
    {
        /** @var array<string, mixed> $tail */
        $tail = (array) config('companion.tail', []);

        $every = (int) ($tail['every'] ?? 0);
        $variants = array_values((array) ($tail['variants'] ?? []));
        $messages = array_values((array) ($tail['messages'] ?? []));
        $roomEvery = (int) ($tail['room_every'] ?? 0);
        $roomObjects = array_values((array) ($tail['room_objects'] ?? []));
        $types = array_values((array) config('companion.item_types', []));
        $displayNames = (array) config('companion.item_display_names', []);

        // An absent or incomplete tail block simply ends the ladder, which is
        // what happened before this existed.
        if ($every < 1 || $variants === [] || $messages === [] || $types === []) {
            return [];
        }

        $base = $this->lastAuthoredInsightThreshold();
        $unlocks = [];

        for ($rung = 1; ; $rung++) {
            $at = $base + $rung * $every;

            if (count($insights) < $at) {
                break;
            }

            $index = $rung - 1;
            $type = $types[$index % count($types)];
            $variant = $variants[$index % count($variants)];

            $bringsObject = $roomEvery > 0
                && $roomObjects !== []
                && $rung % $roomEvery === 0;

            $unlocks[] = [
                'kind' => 'item',
                'name' => $type,
                'variant' => $variant,
                'room_object' => $bringsObject
                    ? $roomObjects[(intdiv($rung, $roomEvery) - 1) % count($roomObjects)]
                    : null,
                // {type} is rendered through the display-noun map, not the
                // raw type: `shoes` and `glasses` are plural, and "another
                // shoes" is not a sentence. `name` above stays the raw type —
                // that is what the renderer and CompanionState key items by.
                'message' => str_replace(
                    ['{name}', '{type}', '{variant}'],
                    [$name, $displayNames[$type] ?? $type, $variant],
                    $messages[$index % count($messages)],
                ),
                'unlocked_at' => $insights[$at - 1]['at']->toIso8601String(),
            ];
        }

        return $unlocks;
    }

    /**
     * The insight count the last authored insight rung sits at — where the tail
     * starts counting from. Read from the ladder rather than hardcoded, so
     * appending an authored rung still moves the tail along behind it.
     */
    private function lastAuthoredInsightThreshold(): int
    {
        /** @var array<int, array<string, mixed>> $ladder */
        $ladder = config('companion.ladder', []);

        $thresholds = array_map(
            static fn (array $entry): int => (int) ($entry['at'] ?? 0),
            array_filter(
                $ladder,
                static fn (array $entry): bool => ($entry['trigger'] ?? 'logs') === 'insights',
            ),
        );

        return $thresholds === [] ? 0 : max($thresholds);
    }
}
