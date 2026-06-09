import { Head } from '@inertiajs/react';

import CoachLayout from '@/layouts/coach-layout';
import { BottomNav } from '@/patyourself/bottom-nav';
import {
    ChatComposer,
    ChatThread,
    useChatThread,
} from '@/patyourself/chat/chat-home';
import type { IntentionData } from '@/patyourself/types';

interface CoachProps {
    intentions: IntentionData[];
}

/**
 * Chat home — the primary daily-driver screen. A message thread with inline
 * action cards (rendered from the user's LLM-authored loops) and a composer,
 * inside the shared CoachLayout shell. Sending is stubbed locally; Task 22
 * wires it to the live coach (POST /chat).
 */
export default function Coach({ intentions }: CoachProps) {
    const { messages, send } = useChatThread(intentions);

    return (
        <CoachLayout
            title="Coach"
            footer={<ChatComposer onSend={send} />}
            bottomNav={<BottomNav />}
        >
            <Head title="Coach" />
            <ChatThread messages={messages} />
        </CoachLayout>
    );
}
