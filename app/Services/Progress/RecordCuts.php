<?php

namespace App\Services\Progress;

use App\Models\ActionLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The record, sliced four ways: the day an occasion fell on, and the three
 * structured context fields recorded beside its outcome.
 *
 * Every cut answers one question — "of the occasions that decided something,
 * which held?" — and every cut answers it over the *same* set. That is the
 * invariant this class exists to hold:
 *
 * - **Skips never count.** `decided = completed + failed`. A skipped occasion
 *   never happened, so it decided nothing and appears in no denominator. It is
 *   reported separately in the page's provenance line instead.
 * - **Every cut sums to that same decided count.** An occasion with no context
 *   recorded is not dropped; it lands in an explicit "Not recorded" row. Drop
 *   it instead and two cuts of one record quietly disagree about how much
 *   record there is, which is the one way a page of percentages can lie without
 *   showing a wrong number anywhere.
 *
 * Rates are never rounded here. Rows carry `held` and `decided`, and rendering
 * decides what to do with them — with four occasions a percentage hides its own
 * denominator, and that judgement belongs at the point of display.
 */
final class RecordCuts
{
    /** Ordered so the week reads as a week, not as a frequency ranking. */
    private const DAYS = [
        'Monday', 'Tuesday', 'Wednesday', 'Thursday',
        'Friday', 'Saturday', 'Sunday',
    ];

    public const NOT_RECORDED = 'Not recorded';

    /**
     * @param  Collection<int, array{outcome: string, occurred_at: CarbonInterface, context_fields: ?array<string, mixed>}>  $decided
     * @return list<array{key: string, label: string, rows: list<array{name: string, held: int, decided: int}>}>
     */
    public function for(Collection $decided): array
    {
        return [
            [
                'key' => 'day',
                'label' => 'Day of the week',
                // Derived from the occasion's own date, so it answers "which
                // day does this go wrong on" rather than "which day did you
                // get round to typing it".
                'rows' => $this->group(
                    $decided,
                    fn (array $log): string => $log['occurred_at']->format('l'),
                    self::DAYS,
                ),
            ],
            [
                'key' => 'place',
                'label' => 'Where you were',
                'rows' => $this->group($decided, fn (array $log): ?string => $this->field($log, 'place')),
            ],
            [
                'key' => 'with',
                'label' => 'Who you were with',
                'rows' => $this->group($decided, function (array $log): ?string {
                    $with = $log['context_fields']['with_others'] ?? null;

                    return $with === null ? null : ($with ? 'With others' : 'Alone');
                }),
            ],
            [
                'key' => 'before',
                'label' => 'What came before',
                'rows' => $this->group($decided, fn (array $log): ?string => $this->field($log, 'preceded_by')),
            ],
        ];
    }

    /**
     * One row per distinct value, plus a "Not recorded" row for everything the
     * key could not name.
     *
     * A null from `$key` is never dropped. The rows of every cut have to add up
     * to the same decided total, and silently discarding the occasions with no
     * context recorded is what breaks that.
     *
     * @param  Collection<int, array{outcome: string, occurred_at: CarbonInterface, context_fields: ?array<string, mixed>}>  $decided
     * @param  callable(array<string, mixed>): ?string  $key
     * @param  list<string>|null  $order  Fixed row order, for a cut whose groups have a natural sequence.
     * @return list<array{name: string, held: int, decided: int}>
     */
    private function group(Collection $decided, callable $key, ?array $order = null): array
    {
        $groups = $decided->groupBy(fn (array $log): string => $key($log) ?? self::NOT_RECORDED);

        $rows = $groups->map(fn (Collection $logs, string $name): array => [
            'name' => $name,
            'held' => $logs->where('outcome', ActionLog::OUTCOME_COMPLETED)->count(),
            'decided' => $logs->count(),
        ]);

        $sorted = $order === null
            // No natural order, so the biggest evidence leads. "Not recorded"
            // is pinned last wherever it lands: it is the absence of a finding,
            // never one.
            ? $rows->sortBy([
                fn (array $a, array $b): int => ($a['name'] === self::NOT_RECORDED ? 1 : 0)
                    <=> ($b['name'] === self::NOT_RECORDED ? 1 : 0),
                fn (array $a, array $b): int => $b['decided'] <=> $a['decided'],
            ])
            : $rows->sortBy(fn (array $row): int => array_search($row['name'], [...$order, self::NOT_RECORDED], true));

        return $sorted->values()->all();
    }

    /** @param  array{context_fields: ?array<string, mixed>}  $log */
    private function field(array $log, string $name): ?string
    {
        $value = $log['context_fields'][$name] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
