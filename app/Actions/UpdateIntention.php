<?php

namespace App\Actions;

use App\Models\Action;
use App\Models\Intention;
use App\Services\Scheduling\ReanchorsSeries;
use Carbon\CarbonImmutable;

/**
 * Updates an existing loop from validated input. Only keys present in the
 * payload are touched, so partial (PATCH-style) edits leave the rest intact.
 * The only place the manual update flow writes to the database.
 */
final readonly class UpdateIntention
{
    /**
     * The behavioural chain. Corrections to these four are a different kind of
     * edit from a retitle or a pause: they say the hypothesis about the
     * behaviour was wrong and has been revised.
     *
     * @var list<string>
     */
    private const CHAIN_FIELDS = ['cue', 'craving', 'response', 'reward'];

    public function __construct(private ReanchorsSeries $reanchor) {}

    /**
     * @param  array<string, mixed>  $data  Validated subset of loop fields.
     */
    public function handle(Intention $intention, array $data): Intention
    {
        $fields = array_intersect_key($data, array_flip([
            'title',
            'description',
            'type',
            'status',
            'workflow',
            ...self::CHAIN_FIELDS,
        ]));

        $wasPaused = $intention->status === Intention::STATUS_PAUSED;
        $corrected = $this->chainFieldsChangedBy($intention, $fields);

        $intention->update($fields);

        if ($corrected !== []) {
            $this->recordChainRevision($intention, $corrected);
        }

        if ($wasPaused && $intention->status === Intention::STATUS_ACTIVE) {
            $this->reanchorStaleActions($intention);
        }

        return $intention;
    }

    /**
     * Which of cue / craving / response / reward this payload actually changes.
     *
     * Compared against the stored values rather than taken from the payload's
     * keys: re-sending the craving unchanged is not a correction, and a
     * correction that did not correct anything should leave no trace.
     *
     * @param  array<string, mixed>  $fields
     * @return list<string>
     */
    private function chainFieldsChangedBy(Intention $intention, array $fields): array
    {
        return array_values(array_filter(
            self::CHAIN_FIELDS,
            static fn (string $field): bool => array_key_exists($field, $fields)
                && $fields[$field] !== $intention->getOriginal($field),
        ));
    }

    /**
     * Append the correction to the loop's own history.
     *
     * The chain is corrected in place — a loop describes one behaviour, and
     * there is only ever one current description of it — so the edit would
     * otherwise leave no record that the hypothesis ever moved. Kept in the
     * existing `metadata` column rather than a new table: it is a short list of
     * timestamps, and it belongs to the loop rather than to anything reading it.
     *
     * @param  list<string>  $fields
     */
    private function recordChainRevision(Intention $intention, array $fields): void
    {
        $metadata = $intention->metadata ?? [];
        $metadata['chain_revisions'][] = [
            'at' => CarbonImmutable::now()->toIso8601String(),
            'fields' => $fields,
        ];

        $intention->update(['metadata' => $metadata]);
    }

    /**
     * A loop can sit paused for days before the user activates it, leaving any
     * clock action anchored in the past — it would materialise a run of
     * occasions the user never had the chance to act on the moment the loop
     * went live. Push each one to its next real occurrence. Only genuinely
     * stale actions are touched; a future-dated one is left as the user
     * scheduled it. Anchored actions carry no clock time and are left alone.
     */
    private function reanchorStaleActions(Intention $intention): void
    {
        $timezone = $intention->user->timezone ?? (string) config('app.timezone');
        $now = CarbonImmutable::now();

        $staleActions = $intention->actions()
            ->where('status', '!=', Action::STATUS_ARCHIVED)
            ->whereNotNull('series_started_at')
            ->where('series_started_at', '<=', $now)
            ->get();

        // The loop's timezone did not change between pause and reactivation,
        // so the anchor was authored in, and is re-armed in, the same zone.
        $this->reanchor->forActions($staleActions, $timezone, $timezone);
    }
}
