import { Head, router } from '@inertiajs/react';
import { useEffect } from 'react';

import CoachLayout from '@/layouts/coach-layout';
import { BottomNav } from '@/patyourself/bottom-nav';
import {
    ChatComposer,
    ChatThread,
    useChatThread,
} from '@/patyourself/chat/chat-home';
import type { IntentionData, ThreadMessage } from '@/patyourself/types';

interface CoachProps {
    intentions: IntentionData[];
    thread?: ThreadMessage[];
    userTimezone?: string | null;
}

/**
 * Chat home — the primary daily-driver screen. A message thread with inline
 * action cards (rendered from the user's LLM-authored loops) and a composer,
 * inside the shared CoachLayout shell. Messages post to the live coach
 * (POST /chat) and quick-log taps post to the action's log endpoint.
 *
 * The `thread` prop carries the server-side stored conversation (max 50 turns);
 * when present the hook hydrates the UI from that history instead of a greeting.
 */
export default function Coach({
    intentions,
    thread = [],
    userTimezone,
}: CoachProps) {
    const { messages, send, log, reschedule } = useChatThread(
        intentions,
        thread,
    );

    useEffect(() => {
        if (userTimezone) {
            return;
        }

        const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;

        if (tz) {
            router.patch(
                '/settings/timezone',
                { timezone: tz },
                { preserveScroll: true, preserveState: true },
            );
        }
    }, [userTimezone]);

    return (
        <CoachLayout
            title="Coach"
            footer={<ChatComposer onSend={send} />}
            bottomNav={<BottomNav />}
        >
            <Head title="Coach" />
            <ChatThread
                messages={messages}
                onLog={log}
                onReschedule={reschedule}
                onSuggest={send}
            />
        </CoachLayout>
    );
}
