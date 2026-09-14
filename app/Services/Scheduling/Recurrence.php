<?php

namespace App\Services\Scheduling;

/**
 * The recurrence rules SP1 supports. The authoring layer also accepts the token
 * "once" (and null), which both mean a one-off action — represented as a null
 * recurrence (a set scheduled_for with no repeat rule), so they map to null here.
 */
enum Recurrence: string
{
    case Daily = 'daily';
    case Weekdays = 'weekdays';
    case Weekly = 'weekly';
    case Fortnightly = 'fortnightly';
    case Monthly = 'monthly';

    /**
     * Map an authored recurrence token to a case, or null for a one-off
     * ("once" / null / anything that is not a recurring rule).
     *
     * Delegates to the generated `tryFrom()` rather than listing the cases
     * again: a hand-written match is one more place a new case has to be
     * remembered, and forgetting it here would accept the token at the
     * boundary and then silently store a one-off. `once` is not a case, so it
     * falls through to null exactly as it always has.
     */
    public static function tryFromToken(?string $token): ?self
    {
        return $token === null ? null : self::tryFrom($token);
    }

    /**
     * Every token the authoring surfaces accept: the recurring rules, plus
     * `once` for a one-off.
     *
     * This is the one list. It was written out by hand in ten places — four
     * request classes, the authoring DTO, two MCP schemas and the client's
     * select controls — which is how a form comes to offer a cadence the API
     * refuses, with the suite green because nothing compared the lists.
     *
     * `once` leads because that is the order the controls read in, shortest
     * commitment first.
     *
     * @return list<string>
     */
    public static function tokens(): array
    {
        return [
            'once',
            ...array_map(static fn (self $rule): string => $rule->value, self::cases()),
        ];
    }
}
