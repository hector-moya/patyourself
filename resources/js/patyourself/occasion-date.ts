/**
 * Format the calendar day an ISO timestamp names, in the offset it was sent
 * with — not in the browser's timezone.
 *
 * The server localises these to the user's own timezone, so the date part of
 * the string already *is* the user's day. Letting `Date` reinterpret it in the
 * browser's zone shifts a late-evening occasion onto the wrong day, which is
 * exactly the confusion an outcome history cannot afford.
 */
export function formatOccasionDay(
    iso: string,
    options: Intl.DateTimeFormatOptions = {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    },
): string {
    const [datePart] = iso.split('T');
    const [year, month, day] = datePart.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString(
        undefined,
        { ...options, timeZone: 'UTC' },
    );
}
