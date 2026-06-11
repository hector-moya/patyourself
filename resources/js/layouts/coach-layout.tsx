import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

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
     * Bottom tab bar. Navigation itself ships in the routing task; the shell
     * only reserves and positions the region.
     */
    bottomNav?: ReactNode;
    /** Sticky region between the scroll area and the bottom nav (e.g. a chat composer). */
    footer?: ReactNode;
    /** Drop the main scroll-area padding for edge-to-edge screens (chat, media). */
    flush?: boolean;
    className?: string;
}

/**
 * The shared app shell every PatYourSelf screen renders inside.
 *
 * A mobile-first column: full-bleed on phones, a centered ~md frame on
 * larger viewports, with a sticky header, a scrollable main region, and
 * optional sticky footer / bottom-nav slots. Purely presentational — it
 * holds no navigation or data of its own.
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
    className,
}: CoachLayoutProps) {
    const showDefaultHeader = header === undefined;

    return (
        <>
            {title && <Head title={title} />}
            <div className="py-frame-bg relative flex min-h-dvh justify-center">
                <span aria-hidden className="py-frame-mark">
                    patyourself
                </span>
                <div className="relative z-10 flex min-h-dvh w-full max-w-app flex-col bg-background shadow-[0_0_60px_-15px_rgba(36,31,27,0.22)] sm:my-0">
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
                            !flush && 'px-4 py-5',
                            className,
                        )}
                    >
                        {children}
                    </main>

                    {footer && (
                        <div className="sticky bottom-0 border-t border-border bg-background/95 backdrop-blur">
                            {footer}
                        </div>
                    )}

                    {bottomNav && (
                        <nav className="sticky bottom-0 flex h-bottom-nav items-stretch border-t border-border bg-background/95 backdrop-blur">
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
        <header className="sticky top-0 z-10 flex h-header items-center gap-3 border-b border-border bg-background/95 px-4 backdrop-blur">
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
