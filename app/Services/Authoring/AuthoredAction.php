<?php

namespace App\Services\Authoring;

/**
 * The concrete, schedulable action the coach proposes alongside a strategy: what
 * to do and when. A "clock" action carries a local HH:MM + recurrence the
 * scheduler can fire; an "anchored" action carries an event phrase ("after
 * coffee") and is stored but never auto-fired. Carries no persistence concerns;
 * AuthorIntention / StartExperiment turn it into an Action row.
 */
final readonly class AuthoredAction
{
    private const KINDS = ['clock', 'anchored'];

    private const RECURRENCES = ['once', 'daily', 'weekdays', 'weekly'];

    public function __construct(
        public string $title,
        public ?string $description,
        public string $kind,
        public ?string $time,
        public ?string $recurrence,
        public ?string $anchor,
    ) {}

    /**
     * Build from the agent's `action` sub-array. Returns null when the block is
     * absent. Throws when it is present but structurally invalid, so a malformed
     * response writes nothing (consistent with the other authoring guards).
     *
     * @param  array<string, mixed>|null  $data
     *
     * @throws AuthoringException
     */
    public static function fromStructured(?array $data): ?self
    {
        if ($data === null || $data === []) {
            return null;
        }

        $title = is_string($data['title'] ?? null) ? trim($data['title']) : '';
        if ($title === '') {
            throw AuthoringException::emptyResponse('intention-author');
        }

        $schedule = is_array($data['schedule'] ?? null) ? $data['schedule'] : [];
        $kind = is_string($schedule['kind'] ?? null) ? trim($schedule['kind']) : '';
        if (! in_array($kind, self::KINDS, true)) {
            throw AuthoringException::emptyResponse('intention-author');
        }

        $time = null;
        $recurrence = null;
        $anchor = null;

        if ($kind === 'clock') {
            $time = is_string($schedule['time'] ?? null) ? trim($schedule['time']) : '';
            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                throw AuthoringException::emptyResponse('intention-author');
            }

            $recurrence = is_string($schedule['recurrence'] ?? null) ? trim($schedule['recurrence']) : 'once';
            if (! in_array($recurrence, self::RECURRENCES, true)) {
                throw AuthoringException::emptyResponse('intention-author');
            }
        } else {
            $anchor = is_string($schedule['anchor'] ?? null) ? trim($schedule['anchor']) : '';
            if ($anchor === '') {
                throw AuthoringException::emptyResponse('intention-author');
            }
        }

        return new self(
            title: $title,
            description: isset($data['description']) ? (($d = trim((string) $data['description'])) !== '' ? $d : null) : null,
            kind: $kind,
            time: $time !== '' ? $time : null,
            recurrence: $recurrence,
            anchor: $anchor !== '' ? $anchor : null,
        );
    }

    /**
     * Lenient variant of {@see fromStructured()}: returns null instead of
     * throwing when the block is malformed or partial, rather than failing the
     * whole payload over one optional action.
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function tryFromStructured(?array $data): ?self
    {
        try {
            return self::fromStructured($data);
        } catch (AuthoringException) {
            return null;
        }
    }
}
