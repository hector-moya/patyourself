/**
 * PatYourSelf — the desktop side rail. The persistent left-hand navigation
 * that replaces the mobile bottom-nav on wide viewports (`lg` and up): a
 * branded wordmark, the same primary tabs the bottom-nav uses (so the two
 * never drift), and a footer link into settings. Purely a navigation surface;
 * it derives its active state and unread count from the current page.
 *
 * Hidden below `lg` — phones keep the bottom-nav. The shared tab list lives in
 * `nav-tabs`.
 */
import { Link, usePage } from '@inertiajs/react';
import { Settings } from 'lucide-react';

import AppLogoIcon from '@/components/app-logo-icon';
import { cn } from '@/lib/utils';
import { NAV_TABS, isTabActive } from './nav-tabs';
import { Icon } from './primitives';

export function AppRail() {
    const { url, props } = usePage();
    const path = url.split('?')[0];
    const unread = props.unread_notifications_count ?? 0;

    return (
        <aside className="sticky top-0 hidden h-dvh w-60 shrink-0 flex-col border-r border-border bg-secondary/60 px-3 py-5 backdrop-blur lg:flex">
            <Link
                href="/dashboard"
                className="mb-5 flex items-center gap-2.5 px-2.5"
                aria-label="patyourself — go to coach"
            >
                <span className="flex size-8 items-center justify-center rounded-[10px] bg-primary text-primary-foreground shadow-[0_6px_16px_-6px_rgba(226,107,62,0.7)]">
                    <AppLogoIcon className="size-[18px] fill-current" />
                </span>
                <span className="font-display text-xl font-extrabold tracking-[-0.03em] text-foreground">
                    patyourself
                </span>
            </Link>

            <nav className="flex flex-col gap-1">
                {NAV_TABS.map((tab) => {
                    const active = isTabActive(tab, path);
                    const showBadge = !!tab.showUnreadBadge && unread > 0;

                    return (
                        <Link
                            key={tab.href}
                            href={tab.href}
                            aria-current={active ? 'page' : undefined}
                            className={cn(
                                'relative flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold transition-colors',
                                active
                                    ? 'bg-accent text-primary'
                                    : 'text-foreground/65 hover:bg-card hover:text-foreground',
                            )}
                        >
                            {active && (
                                <span
                                    aria-hidden="true"
                                    className="absolute top-2 bottom-2 -left-3 w-1 rounded-r-full bg-primary"
                                />
                            )}
                            <Icon name={tab.icon} size={18} />
                            <span>{tab.label}</span>
                            {showBadge && (
                                <span
                                    data-testid="rail-inbox-badge"
                                    aria-label={`${unread} unread cues`}
                                    className={cn(
                                        'ml-auto flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 font-mono text-[11px] font-bold tabular-nums',
                                        active
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-card text-muted-foreground',
                                    )}
                                >
                                    {unread > 9 ? '9+' : unread}
                                </span>
                            )}
                        </Link>
                    );
                })}
            </nav>

            <div className="mt-auto border-t border-border pt-2">
                <Link
                    href="/settings/profile"
                    className="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold text-foreground/65 transition-colors hover:bg-card hover:text-foreground"
                >
                    <Settings className="size-[18px]" strokeWidth={2} />
                    <span>Settings</span>
                </Link>
            </div>
        </aside>
    );
}
