<?php

namespace App\Services\Companion;

use App\Actions\UpdateIntention;
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
                'message' => (string) $entry['message'],
                // When this one arrived: the moment of the trigger that earned
                // it, not of the request that noticed it.
                'unlocked_at' => $moments[$at - 1]->toIso8601String(),
            ];
        }

        return new CompanionState(count($logs), count($insights), $unlocks);
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
     * @return list<CarbonImmutable>
     */
    private function logMoments(User $user): array
    {
        return $user->actionLogs()
            ->orderBy('logged_at')
            ->orderBy('id')
            ->pluck('logged_at')
            ->map(static fn ($moment): CarbonImmutable => CarbonImmutable::instance($moment))
            ->all();
    }

    /**
     * Every insight event this user has recorded, oldest first.
     *
     * Four sources, all of them existing records rather than a judgement call:
     * an experiment concluded, a new version started, the loop's chain
     * corrected, a reflection written. Merged and re-sorted because a ladder
     * entry needs the Nth insight overall, not the Nth of one kind.
     *
     * @return list<CarbonImmutable>
     */
    private function insightMoments(User $user): array
    {
        $moments = [
            ...$this->concludedExperiments($user),
            ...$this->startedExperiments($user),
            ...$this->chainCorrections($user),
            ...$this->reflections($user),
        ];

        // By timestamp rather than by object: two moments in the same second
        // from different sources still need a stable, total order.
        usort(
            $moments,
            static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a->getTimestamp() <=> $b->getTimestamp(),
        );

        return $moments;
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
}
