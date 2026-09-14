/**
 * The recurrences a person can choose, and which of them ask for a start date.
 *
 * Mirrored from `App\Services\Scheduling\Recurrence` on the server, not shared
 * with it: the enum is PHP and this ships to the browser. A cadence added on
 * the server and missing here would simply never be offered, so the mirror is
 * held in place by a test — `RecurrenceVocabularyTest` reads this file and
 * asserts the values against `Recurrence::tokens()`, in this order.
 *
 * `once` leads: shortest commitment first.
 */
export const RECURRENCES: ReadonlyArray<{ value: string; label: string }> = [
    { value: 'once', label: 'Once' },
    { value: 'daily', label: 'Daily' },
    { value: 'weekdays', label: 'Weekdays' },
    { value: 'weekly', label: 'Weekly' },
    { value: 'fortnightly', label: 'Fortnightly' },
    { value: 'monthly', label: 'Monthly' },
];

/**
 * Recurrences that ask for a start date, because a date names a *day* rather
 * than an instant and for these the day carries meaning: weekly and fortnightly
 * pick the weekday (and for fortnightly, which fortnight), monthly picks the
 * day of the month, and a one-off's date is the event itself.
 *
 * Daily and weekdays repeat on every day they apply to, so a date says nothing
 * about them — and posting no date is what keeps them on the server's
 * derived-anchor path.
 */
const NAME_A_DAY = ['once', 'weekly', 'fortnightly', 'monthly'];

export function namesADay(recurrence: string): boolean {
    return NAME_A_DAY.includes(recurrence);
}
