/**
 * The app's primary navigation, shared by the mobile bottom-nav and the
 * desktop side rail so both stay in lockstep. Each tab links one of the app's
 * top-level screens; the loop- and progress-detail screens nest under their
 * list, so a tab stays active for its whole section.
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
        label: 'Coach',
        icon: 'message-circle',
        href: '/dashboard',
        match: ['/dashboard'],
    },
    {
        label: 'Loops',
        icon: 'git-branch',
        href: '/intentions',
        match: ['/intentions'],
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
