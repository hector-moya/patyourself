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

/**
 * Settings — reachable from every viewport, but not one of the primary tabs.
 *
 * Kept out of NAV_TABS because the two navigation surfaces place it
 * differently: the rail sets it below a rule at the foot, away from the five
 * screens the app is actually about, while the bottom bar has no foot to put
 * it in and carries it as the last tab. Defined once here so the destination
 * and its active-matching cannot drift between them.
 *
 * `/settings` itself redirects to the profile page, so the match list covers
 * every screen the settings shell owns.
 */
export const SETTINGS_TAB: NavTab = {
    label: 'Settings',
    icon: 'settings',
    href: '/settings/profile',
    match: ['/settings'],
};

/** Whether `tab` owns the current `path` (exact match or a nested route). */
export function isTabActive(tab: NavTab, path: string): boolean {
    return tab.match.some((m) => path === m || path.startsWith(`${m}/`));
}
