import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import { useCurrentUrl } from '@/hooks/use-current-url';
import CoachLayout from '@/layouts/coach-layout';
import { cn, toUrl } from '@/lib/utils';
import { BottomNav } from '@/patyourself/bottom-nav';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editNotifications } from '@/routes/notifications';
import { edit } from '@/routes/profile';
import { edit as editRecord } from '@/routes/record';
import { edit as editSecurity } from '@/routes/security';
import { edit as editTimezone } from '@/routes/timezone';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: edit(),
        icon: null,
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: null,
    },
    {
        title: 'Notifications',
        href: editNotifications(),
        icon: null,
    },
    {
        title: 'Timezone',
        href: editTimezone(),
        icon: null,
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: null,
    },
    {
        title: 'Your record',
        href: editRecord(),
        icon: null,
    },
];

/**
 * The settings shell — the app's one shell, with a settings nav inside it.
 *
 * Settings used to resolve to `[AppLayout, SettingsLayout]`, and AppLayout is
 * the Laravel starter kit's: its own sidebar listing Dashboard, Repository and
 * Documentation, and on a phone a hamburger that opened that sidebar over the
 * page. So walking into settings replaced the whole app — the five screens
 * disappeared and were replaced by links to someone else's scaffolding, with no
 * way back except the browser.
 *
 * Now it renders {@link CoachLayout} like every other screen, which is what
 * keeps the rail, the tab bar and the way back present throughout. The section
 * list below is settings' own navigation and nothing more.
 *
 * `breadcrumbs` arrives from each page's `Page.layout` object, which AppLayout
 * used to consume. It is accepted and ignored rather than removed from the
 * pages: this shell states where you are in its header, and a breadcrumb trail
 * one level deep says nothing the heading does not.
 */
export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <CoachLayout
            title="Settings"
            header={
                <div className="t-head">
                    <div>
                        <p className="t-date">Your account</p>
                        <h1 className="t-day">Settings</h1>
                    </div>
                </div>
            }
            flush
            bottomNav={<BottomNav />}
        >
            <div className="t-body">
                <div className="t-col t-col--wide">
                    <div className="flex flex-col gap-8 lg:flex-row lg:gap-12">
                        <aside className="lg:w-48 lg:shrink-0">
                            <nav
                                className="flex flex-col gap-0.5"
                                aria-label="Settings"
                            >
                                {sidebarNavItems.map((item, index) => (
                                    <Link
                                        key={`${toUrl(item.href)}-${index}`}
                                        href={item.href}
                                        aria-current={
                                            isCurrentOrParentUrl(item.href)
                                                ? 'page'
                                                : undefined
                                        }
                                        className={cn(
                                            'rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                            isCurrentOrParentUrl(item.href)
                                                ? 'bg-accent text-primary'
                                                : 'text-foreground/70 hover:bg-card hover:text-foreground',
                                        )}
                                    >
                                        {item.title}
                                    </Link>
                                ))}
                            </nav>
                        </aside>

                        <section className="min-w-0 flex-1 space-y-12 lg:max-w-2xl">
                            {children}
                        </section>
                    </div>
                </div>
            </div>
        </CoachLayout>
    );
}
