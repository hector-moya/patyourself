import CoachLayout from '@/layouts/coach-layout';
import { BottomNav } from '@/patyourself/bottom-nav';

interface InboxNotification {
    id: string;
    action_id: number | null;
    intention_id: number | null;
    title: string | null;
    fired_at: string | null;
    read_at: string | null;
}

interface InboxProps {
    notifications: InboxNotification[];
}

/**
 * The in-app inbox — delivered cues for the user's fired actions. Placeholder
 * shell wired to the controller's `notifications` payload; the full list UI and
 * read controls land in the dedicated inbox-page task.
 */
export default function Inbox({ notifications }: InboxProps) {
    return (
        <CoachLayout title="Inbox" bottomNav={<BottomNav />}>
            {notifications.length === 0 ? (
                <p className="text-sm text-muted-foreground">No cues yet.</p>
            ) : (
                <ul className="flex flex-col gap-2">
                    {notifications.map((notification) => (
                        <li
                            key={notification.id}
                            className="rounded-xl border border-border bg-card p-3"
                        >
                            <span className="font-semibold text-foreground">
                                {notification.title}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </CoachLayout>
    );
}
