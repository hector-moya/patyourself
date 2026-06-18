import { Link, router } from '@inertiajs/react';

import CoachLayout from '@/layouts/coach-layout';
import { cn } from '@/lib/utils';
import { BottomNav } from '@/patyourself/bottom-nav';
import type { NotificationData } from '@/patyourself/types';

interface InboxProps {
    notifications: NotificationData[];
}

/**
 * The inbox — delivered cues (notifications raised when the user's actions
 * fired). Unread cues lead with a dot; tapping one marks it read and opens its
 * loop. "Mark all read" clears the lot. Read state drives the bottom-nav badge.
 */
export default function Inbox({ notifications }: InboxProps) {
    const hasUnread = notifications.some(
        (notification) => !notification.read_at,
    );

    return (
        <CoachLayout
            title="Inbox"
            bottomNav={<BottomNav />}
            headerActions={
                hasUnread ? (
                    <button
                        type="button"
                        onClick={() =>
                            router.patch(
                                '/inbox/read-all',
                                {},
                                { preserveScroll: true },
                            )
                        }
                        className="text-xs font-medium text-primary"
                    >
                        Mark all read
                    </button>
                ) : undefined
            }
        >
            {notifications.length === 0 ? (
                <EmptyState />
            ) : (
                <ul className="flex flex-col gap-2">
                    {notifications.map((notification) => (
                        <li key={notification.id}>
                            <InboxItem notification={notification} />
                        </li>
                    ))}
                </ul>
            )}
        </CoachLayout>
    );
}

function InboxItem({ notification }: { notification: NotificationData }) {
    const unread = !notification.read_at;
    const className = cn(
        'flex items-center gap-3 rounded-xl border border-border bg-card p-3 transition-colors',
        !unread && 'opacity-70',
    );

    const content = (
        <>
            {unread && (
                <span
                    data-testid="unread-dot"
                    aria-label="Unread"
                    className="size-2 shrink-0 rounded-full bg-primary"
                />
            )}
            <span
                className={cn(
                    'flex-1 text-sm text-foreground',
                    unread && 'font-medium',
                )}
            >
                {notification.title ?? 'Action'} — due now
            </span>
            <span className="shrink-0 text-xs text-muted-foreground">
                {formatFiredAt(notification.fired_at)}
            </span>
        </>
    );

    // A cue without a loop to open (malformed/legacy payload) renders as a
    // static row rather than a dead link to /intentions/.
    if (notification.intention_id === null) {
        return <div className={className}>{content}</div>;
    }

    return (
        <Link
            href={`/intentions/${notification.intention_id}`}
            onClick={() => {
                if (unread) {
                    router.patch(
                        `/inbox/${notification.id}/read`,
                        {},
                        { preserveScroll: true, preserveState: true },
                    );
                }
            }}
            className={cn(
                className,
                'hover:border-foreground/20 hover:bg-accent/40',
            )}
        >
            {content}
        </Link>
    );
}

function formatFiredAt(firedAt: string | null): string {
    if (!firedAt) {
        return '';
    }

    return new Date(firedAt).toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
    });
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center py-16 text-center">
            <p className="text-sm font-medium text-foreground">No cues yet</p>
            <p className="mt-1 text-xs text-muted-foreground">
                When an action's time arrives, it'll show up here.
            </p>
        </div>
    );
}
