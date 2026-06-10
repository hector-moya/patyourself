import { Head } from '@inertiajs/react';

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
export default function Coach({ intentions, thread = [] }: CoachProps) {
    const { messages, send, log } = useChatThread(intentions, thread);

    return (
        <CoachLayout
            title="Coach"
            footer={<ChatComposer onSend={send} />}
            bottomNav={<BottomNav />}
        >
            <Head title="Coach" />
            <ChatThread messages={messages} onLog={log} onSuggest={send} />
        </CoachLayout>
    );
}
