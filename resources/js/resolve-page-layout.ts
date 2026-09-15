import type { ComponentType, ReactNode } from 'react';

import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

type LayoutComponent = ComponentType<{ children: ReactNode }>;
type PersistentLayout = LayoutComponent | LayoutComponent[] | null;

/**
 * Picks the persistent Inertia layout for a resolved page name.
 *
 * Every first-party app screen (loops, progress, inbox, …) renders its own
 * {@link CoachLayout} shell — side rail, header, bottom-nav — so it resolves to
 * `null` here; wrapping it in a second shell produces a sidebar inside a
 * sidebar. The default is `null`, not a layout: a new page is shell-owning
 * until it explicitly asks otherwise, which is the safe direction for this app.
 *
 * `settings/*` used to resolve to `[AppLayout, SettingsLayout]`, and AppLayout
 * is the Laravel starter kit's — Dashboard, Repository, Documentation, and a
 * hamburger on phones. Opening settings therefore swapped the entire app for
 * the scaffolding it was generated from. SettingsLayout now brings CoachLayout
 * itself, so there is one shell in the app and settings sits inside it.
 *
 * Only `auth/*` still takes a different shell, and it should: signing in
 * happens outside the app, with no rail and no tabs to show.
 */
export function resolvePageLayout(name: string): PersistentLayout {
    switch (true) {
        case name.startsWith('auth/'):
            return AuthLayout;
        case name.startsWith('settings/'):
            return SettingsLayout;
        default:
            return null;
    }
}
