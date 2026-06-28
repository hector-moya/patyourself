import type { ComponentType, ReactNode } from 'react';

import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

type LayoutComponent = ComponentType<{ children: ReactNode }>;
type PersistentLayout = LayoutComponent | LayoutComponent[] | null;

/**
 * Picks the persistent Inertia layout for a resolved page name.
 *
 * Every first-party app screen (coach, intentions, progress, inbox, …) renders
 * its own {@link CoachLayout} shell — side rail, header, bottom-nav — so it must
 * resolve to `null` here; wrapping it in the starter-kit `AppLayout` as well
 * produces a sidebar-inside-a-sidebar. Only the two flows that do NOT bring
 * their own shell opt into a framework layout: `auth/*` and `settings/*`.
 *
 * That's why the default is `null`, not `AppLayout` — a new page is shell-owning
 * until it explicitly asks otherwise, which is the safe direction for this app.
 */
export function resolvePageLayout(name: string): PersistentLayout {
    switch (true) {
        case name.startsWith('auth/'):
            return AuthLayout;
        case name.startsWith('settings/'):
            return [AppLayout, SettingsLayout];
        default:
            return null;
    }
}
