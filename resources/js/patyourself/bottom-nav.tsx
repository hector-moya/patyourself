/**
 * PatYourSelf — the app's primary navigation. Renders inside CoachLayout's
 * reserved bottom-nav slot and links the three screens: Coach (chat home) and
 * Loops (the loops list). The loop-detail screen is reached from a loop, so it
 * has no top-level tab and keeps the Loops tab active.
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
];

export function BottomNav() {
    const { url } = usePage();
    const path = url.split('?')[0];

    return (
        <>
            {TABS.map((tab) => {
                const active = tab.match.some(
                    (m) => path === m || path.startsWith(`${m}/`),
                );

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
                        <Icon name={tab.icon} size={20} />
                        <span>{tab.label}</span>
                    </Link>
                );
            })}
        </>
    );
}
