import { describe, expect, it } from 'vitest';

import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { resolvePageLayout } from './resolve-page-layout';

describe('resolvePageLayout', () => {
    // Every first-party screen owns its CoachLayout shell, so it must resolve to
    // null — wrapping it in AppLayout too produces a sidebar inside a sidebar.
    it.each([
        'coach',
        'inbox',
        'progress/index',
        'progress/show',
        'intentions/index',
        'intentions/show',
        'welcome',
        'landing',
    ])('resolves the shell-owning page "%s" to null', (name) => {
        expect(resolvePageLayout(name)).toBeNull();
    });

    it('wraps auth pages in AuthLayout', () => {
        expect(resolvePageLayout('auth/login')).toBe(AuthLayout);
    });

    it('wraps settings pages in the AppLayout + SettingsLayout stack', () => {
        expect(resolvePageLayout('settings/profile')).toEqual([
            AppLayout,
            SettingsLayout,
        ]);
    });

    it('defaults an unknown page to null rather than the starter AppLayout', () => {
        expect(resolvePageLayout('some/brand-new-page')).toBeNull();
    });
});
