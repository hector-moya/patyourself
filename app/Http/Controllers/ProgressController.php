<?php

namespace App\Http\Controllers;

use App\Models\Intention;
use App\Services\Progress\LoopProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The read-only progress dashboard: the user's active loops as metric cards,
 * each linking into that loop's lab record.
 *
 * A card is about the version that is running, not the loop's whole lifetime.
 * That distinction is the screen: the card names its version and sets it
 * against the one it replaced, and lifetime figures would fold the replaced
 * version's evidence into the number being compared — see
 * {@see LoopProgress::forCurrentVersion()}, which exists for exactly this
 * reason. A loop between experiments has no version to be about, so it falls
 * back to the lifetime record and offers no comparison.
 *
 * The per-loop drill-in that used to live here folded into `/loops/{loop}`,
 * which now carries the current experiment, the per-version evidence and the
 * reflection on one screen. `progress/{intention}` survives as a redirect.
 */
class ProgressController extends Controller
{
    public function index(Request $request, LoopProgress $progress): Response
    {
        $loops = $request->user()->intentions()
            ->active()
            ->with(['activeStrategy', 'latestSummary', 'actionLogs'])
            ->latest()
            ->get()
            ->map(fn (Intention $loop): array => $this->card($loop, $progress))
            ->values();

        return Inertia::render('progress/index', [
            'loops' => $loops->all(),
            'summary' => $this->summary($loops),
        ]);
    }

    /**
     * One loop's card.
     *
     * @return array{id: int, title: string, type: string, version: ?int, day_of_experiment: ?int, streak: array{outcome: ?string, length: int}, completion_rate: ?int, totals: array{completed: int, failed: int, skipped: int}, recent: list<string>, previous_version: ?array{version: int, rate: int}, last_logged_at: ?string, summary_excerpt: ?string}
     */
    private function card(Intention $loop, LoopProgress $progress): array
    {
        $current = $progress->forCurrentVersion($loop);

        // With a version running the card is about that version; without one it
        // is about the loop. The two shapes agree on every key the card reads,
        // so the client renders one card either way.
        $record = $current ?? $progress->forLoop($loop);

        return [
            'id' => $loop->id,
            'title' => $loop->title,
            'type' => $loop->type,
            // Null while no experiment is running — the meta line then names
            // the loop's direction alone rather than inventing a version.
            'version' => $current['version'] ?? null,
            'day_of_experiment' => $current['day_of_experiment'] ?? null,
            'streak' => $record['streak'],
            'completion_rate' => $record['completion_rate'],
            'totals' => $record['totals'],
            'recent' => $record['recent'],
            // What this version is being measured against. Null for a first
            // version, and for one whose predecessors never produced a
            // decision — there is nothing to compare against, and saying so is
            // the honest answer.
            'previous_version' => $current === null
                ? null
                : $progress->previousDecidedVersion($loop),
            'last_logged_at' => $record['last_logged_at'],
            'summary_excerpt' => $this->excerpt($loop->latestSummary?->content),
        ];
    }

    /**
     * What the whole record adds up to, for the line at the top of the screen.
     *
     * Only loops that have decided something count. A loop with nothing logged
     * has not held or failed anything, and folding its zero into the total
     * would drag the rate down for the crime of being new.
     *
     * @param  Collection<int, array<string, mixed>>  $loops
     * @return array{held: int, decided: int, skipped: int, versions_running: int, versions_ahead: int}
     */
    private function summary(Collection $loops): array
    {
        $recording = $loops->filter(fn (array $card): bool => $card['completion_rate'] !== null);

        return [
            'held' => $recording->sum(fn (array $card): int => $card['totals']['completed']),
            'decided' => $recording->sum(
                fn (array $card): int => $card['totals']['completed'] + $card['totals']['failed']
            ),
            'skipped' => $recording->sum(fn (array $card): int => $card['totals']['skipped']),
            'versions_running' => $loops->whereNotNull('version')->count(),
            // Running versions beating the one they replaced. Counted, never
            // assumed: "ahead of what they replaced" is a claim about the
            // record, and the screen only gets to make it when it is true.
            'versions_ahead' => $recording->filter(
                fn (array $card): bool => $card['previous_version'] !== null
                    && $card['completion_rate'] > $card['previous_version']['rate']
            )->count(),
        ];
    }

    /** First line of the rolling summary, trimmed for the index card. */
    private function excerpt(?string $content): ?string
    {
        if ($content === null || trim($content) === '') {
            return null;
        }

        return Str::limit(trim(strtok($content, "\n")), 120);
    }
}
