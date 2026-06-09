import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import CoachLayout from '@/layouts/coach-layout';
import { BottomNav } from '@/patyourself/bottom-nav';
import { CoachScreen } from '@/patyourself/coach-screen';

/**
 * Chat home — the Coach screen, now hosted inside the shared CoachLayout shell
 * with the app-wide bottom navigation. The chat content keeps its ported
 * `py-*` styling via a `.py-host` token wrapper; Task 18 rebuilds the screen
 * natively in the shell. The Coach draws its own in-screen header, so the
 * layout's default header is suppressed.
 */
export default function Coach() {
    const theme = usePyTheme();

    return (
        <CoachLayout header={null} flush bottomNav={<BottomNav />}>
            <Head title="Coach" />
            <div className="py-host flex h-full flex-col" data-theme={theme}>
                <CoachScreen />
            </div>
        </CoachLayout>
    );
}

/** Mirrors the app's global light/dark choice onto the py-* token wrapper. */
function usePyTheme(): 'light' | 'dark' {
    const read = (): 'light' | 'dark' =>
        typeof document !== 'undefined' &&
        document.documentElement.classList.contains('dark')
            ? 'dark'
            : 'light';

    const [theme, setTheme] = useState<'light' | 'dark'>(read);

    useEffect(() => {
        const el = document.documentElement;
        const sync = () =>
            setTheme(el.classList.contains('dark') ? 'dark' : 'light');

        sync();
        const observer = new MutationObserver(sync);
        observer.observe(el, { attributes: true, attributeFilter: ['class'] });

        return () => observer.disconnect();
    }, []);

    return theme;
}
