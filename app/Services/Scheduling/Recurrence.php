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

    /**
     * Map an authored recurrence token to a case, or null for a one-off
     * ("once" / null / anything not a recurring rule).
     */
    public static function tryFromToken(?string $token): ?self
    {
        return match ($token) {
            'daily' => self::Daily,
            'weekdays' => self::Weekdays,
            'weekly' => self::Weekly,
            default => null,
        };
    }
}
