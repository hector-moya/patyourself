/**
 * PatYourSelf — the app's primary navigation. Renders inside CoachLayout's
 * reserved bottom-nav slot and links the app's screens: Coach (chat home),
 * Loops (the loops list), and Inbox (delivered cues, with an unread badge). The
 * loop-detail screen is reached from a loop, so it keeps the Loops tab active.
 */
import { Link, usePage } from '@inertiajs/react';

import { cn } from '@/lib/utils';
import { Icon } from './primitives';

interface Tab {
    label: string;
    icon: string;
    href: string;
    /** A tab is active when the current path starts with one of these. */
    match: string[];
    /** When true, the tab surfaces the unread-cues count as a badge. */
    showUnreadBadge?: boolean;
}

const TABS: Tab[] = [
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
        label: 'Inbox',
        icon: 'bell',
        href: '/inbox',
        match: ['/inbox'],
        showUnreadBadge: true,
    },
];

export function BottomNav() {
    const { url, props } = usePage();
    const path = url.split('?')[0];
    const unread = props.unread_notifications_count ?? 0;

    return (
        <>
            {TABS.map((tab) => {
                const active = tab.match.some(
                    (m) => path === m || path.startsWith(`${m}/`),
                );
                const showBadge = !!tab.showUnreadBadge && unread > 0;

                return (
                    <Link
                        key={tab.href}
                        href={tab.href}
                        className={cn(
                            'flex flex-1 flex-col items-center justify-center gap-0.5 text-xs font-medium transition-colors',
                            active
                                ? 'text-primary'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                        aria-current={active ? 'page' : undefined}
                    >
                        <span className="relative">
                            <Icon name={tab.icon} size={20} />
                            {showBadge && (
                                <span
                                    data-testid="inbox-badge"
                                    aria-label={`${unread} unread cues`}
                                    className="absolute -top-1.5 -right-2.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold text-primary-foreground"
                                >
                                    <span aria-hidden="true">
                                        {unread > 9 ? '9+' : unread}
                                    </span>
                                </span>
                            )}
                        </span>
                        <span>{tab.label}</span>
                    </Link>
                );
            })}
        </>
    );
}
