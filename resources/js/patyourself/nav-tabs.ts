/**
 * The app's primary navigation, shared by the mobile bottom-nav and the
 * desktop side rail so both stay in lockstep. Each tab links one of the app's
 * top-level screens (Today, Loops, Companion, Progress, Inbox); the loop-detail
 * screen nests under its list, so a tab stays active for its whole section.
 */
export interface NavTab {
    label: string;
    icon: string;
    href: string;
    /** A tab is active when the current path equals or sits under one of these. */
    match: string[];
    /** When true, the tab surfaces the unread-cues count as a badge. */
    showUnreadBadge?: boolean;
}

export const NAV_TABS: NavTab[] = [
    {
        // The daily driver, and where Fortify lands every login. First, because
        // "what am I doing today" is the question the app opens on.
        label: 'Today',
        icon: 'sun',
        href: '/dashboard',
        match: ['/dashboard'],
    },
    {
        label: 'Loops',
        icon: 'git-branch',
        href: '/loops',
        match: ['/loops'],
    },
    {
        // Blob's own screen. A door in the nav rather than only the small
        // corner on Today: the corner is how Blob reacts to an outcome just
        // recorded, which is not the same as being able to go and see it.
        //
        // Carries no badge and no count, like every tab that is not Inbox —
        // Blob is something to visit, never something owed.
        label: 'Companion',
        icon: 'sprout',
        href: '/companion',
        match: ['/companion'],
    },
    {
        label: 'Progress',
        icon: 'trending-up',
        href: '/progress',
        match: ['/progress'],
    },
    {
        label: 'Inbox',
        icon: 'bell',
        href: '/inbox',
        match: ['/inbox'],
        showUnreadBadge: true,
    },
];

/** Whether `tab` owns the current `path` (exact match or a nested route). */
export function isTabActive(tab: NavTab, path: string): boolean {
    return tab.match.some((m) => path === m || path.startsWith(`${m}/`));
}
