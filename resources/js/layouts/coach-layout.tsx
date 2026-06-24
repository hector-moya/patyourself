import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';
import { AppRail } from '@/patyourself/app-rail';

interface CoachLayoutProps {
    /** Sets the document <title>. Also used as the default header heading. */
    title?: string;
    children: ReactNode;
    /**
     * Replaces the default header entirely. Pass `null` to render no header
     * (e.g. a full-bleed screen that draws its own).
     */
    header?: ReactNode;
    /** Right-aligned controls in the default header (icon buttons, etc.). */
    headerActions?: ReactNode;
    /** Left-aligned control in the default header (back button, avatar, etc.). */
    headerLeading?: ReactNode;
    /**
     * Bottom tab bar (phones only — the desktop side rail replaces it). The
     * shell reserves the region and hides it from `lg` up.
     */
    bottomNav?: ReactNode;
    /** Sticky region between the scroll area and the bottom nav (e.g. a chat composer). */
    footer?: ReactNode;
    /** Drop the main scroll-area padding for edge-to-edge screens (chat, media). */
    flush?: boolean;
    /**
     * Widen the desktop content column for dashboard/grid screens (loops,
     * progress). Reading screens (chat, detail, inbox) keep the narrower,
     * comfortable measure. No effect on phones.
     */
    wide?: boolean;
    className?: string;
}

/**
 * The shared app shell every PatYourSelf screen renders inside.
 *
 * Responsive by design. On phones it's a mobile-first column: full-bleed, a
 * sticky header, a scrollable main region, and optional sticky footer /
 * bottom-nav slots. From `lg` up it becomes a true desktop layout — a
 * persistent side rail on the left, the bottom-nav hidden, and the content
 * column expanded and centred at a comfortable measure (wider still for
 * dashboard grids via `wide`). Purely presentational beyond the rail.
 */
export default function CoachLayout({
    title,
    children,
    header,
    headerActions,
    headerLeading,
    bottomNav,
    footer,
    flush = false,
    wide = false,
    className,
}: CoachLayoutProps) {
    const showDefaultHeader = header === undefined;
    // The desktop measure: dashboards breathe wide for their grids; everything
    // else keeps a readable column. Below `lg` the parent frame caps the width.
    const measure = wide ? 'lg:max-w-5xl' : 'lg:max-w-2xl';

    return (
        <>
            {title && <Head title={title} />}
            <div className="py-frame-bg relative flex min-h-dvh justify-center lg:justify-start">
                <AppRail />
                <div className="relative z-10 flex min-h-dvh w-full max-w-app flex-col bg-background shadow-[0_0_60px_-15px_rgba(36,31,27,0.22)] sm:my-0 lg:max-w-none lg:flex-1 lg:shadow-none">
                    {showDefaultHeader ? (
                        <CoachHeader
                            title={title}
                            leading={headerLeading}
                            actions={headerActions}
                        />
                    ) : (
                        header
                    )}

                    <main
                        className={cn(
                            'flex-1 overflow-y-auto',
                            !flush && 'px-4 py-5 lg:px-8 lg:py-8',
                            className,
                        )}
                    >
                        {flush ? (
                            children
                        ) : (
                            <div
                                className={cn(
                                    'mx-auto flex min-h-full w-full flex-col',
                                    measure,
                                )}
                            >
                                {children}
                            </div>
                        )}
                    </main>

                    {footer && (
                        <div className="sticky bottom-0 border-t border-border bg-background/95 backdrop-blur">
                            <div
                                className={cn(
                                    'mx-auto w-full',
                                    !flush && measure,
                                    !flush && 'lg:px-4',
                                )}
                            >
                                {footer}
                            </div>
                        </div>
                    )}

                    {bottomNav && (
                        <nav className="sticky bottom-0 flex h-bottom-nav items-stretch border-t border-border bg-background/95 backdrop-blur lg:hidden">
                            {bottomNav}
                        </nav>
                    )}
                </div>
            </div>
        </>
    );
}

interface CoachHeaderProps {
    title?: string;
    leading?: ReactNode;
    actions?: ReactNode;
}

/** Default sticky header: optional leading control, centered/left title, trailing actions. */
export function CoachHeader({ title, leading, actions }: CoachHeaderProps) {
    return (
        <header className="sticky top-0 z-10 flex h-header items-center gap-3 border-b border-border bg-background/95 px-4 backdrop-blur lg:px-8">
            {leading}
            {title && (
                <h1 className="truncate text-base font-semibold text-foreground">
                    {title}
                </h1>
            )}
            {actions && (
                <div className="ml-auto flex items-center gap-1">{actions}</div>
            )}
        </header>
    );
}
