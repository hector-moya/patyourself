import { describe, expect, it } from 'vitest';

import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { resolvePageLayout } from './resolve-page-layout';

describe('resolvePageLayout', () => {
    // Every first-party screen owns its CoachLayout shell, so it must resolve to
    // null — wrapping it in AppLayout too produces a sidebar inside a sidebar.
    it.each([
        'inbox',
        'progress/index',
        'progress/show',
        'loops/index',
        'loops/show',
        'welcome',
        'landing',
    ])('resolves the shell-owning page "%s" to null', (name) => {
        expect(resolvePageLayout(name)).toBeNull();
    });

    it('wraps auth pages in AuthLayout', () => {
        expect(resolvePageLayout('auth/login')).toBe(AuthLayout);
    });

    /**
     * One shell in the app. Settings used to resolve to
     * `[AppLayout, SettingsLayout]`, and AppLayout is the starter kit's —
     * Dashboard, Repository, Documentation and a phone hamburger — so opening
     * settings swapped the whole app for the scaffolding it was generated
     * from. SettingsLayout brings CoachLayout itself now.
     *
     * Named killing mutation: return `[AppLayout, SettingsLayout]` again. This
     * asserts a single layout, not an array, and fails.
     */
    it('gives settings pages the one app shell, not a second one on top', () => {
        expect(resolvePageLayout('settings/profile')).toBe(SettingsLayout);
    });

    it('defaults an unknown page to null rather than a framework layout', () => {
        expect(resolvePageLayout('some/brand-new-page')).toBeNull();
    });
});
