/**
 * PatYourSelf — the app's primary navigation on phones. Renders inside
 * CoachLayout's reserved bottom-nav slot (hidden on desktop, where the side
 * rail takes over) and links the app's screens: Loops (the loops list),
 * Progress, and Inbox (delivered cues, with an unread badge). The tab
 * definitions live in `nav-tabs` so the rail and bar never drift apart.
 */
import { Link, usePage } from '@inertiajs/react';

import { cn } from '@/lib/utils';
import { NAV_TABS, SETTINGS_TAB, isTabActive } from './nav-tabs';
import { Icon } from './primitives';

export function BottomNav() {
    const { url, props } = usePage();
    const path = url.split('?')[0];
    const unread = props.unread_notifications_count ?? 0;

    // Settings last. The rail can tuck it into a footer below a rule; a tab bar
    // has no such place, and leaving it out altogether — which it was — made
    // settings unreachable on a phone entirely.
    const tabs = [...NAV_TABS, SETTINGS_TAB];

    return (
        <>
            {tabs.map((tab) => {
                const active = isTabActive(tab, path);
                const showBadge = !!tab.showUnreadBadge && unread > 0;

                return (
                    <Link
                        key={tab.href}
                        href={tab.href}
                        className={cn(
                            // 10.5px and min-w-0, because the bar carries six
                            // tabs: at 390px that is 65px each, and "Companion"
                            // at the previous 12px wrapped onto a second line
                            // and shunted the whole row taller.
                            'flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 text-[10.5px] font-semibold transition-colors',
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
                        <span className="max-w-full truncate">{tab.label}</span>
                    </Link>
                );
            })}
        </>
    );
}
