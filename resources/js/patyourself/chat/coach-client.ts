import type { IntentionData, LogOutcome } from '@/patyourself/types';

/** One prior turn sent back to the coach for context. */
export interface CoachTurn {
    role: 'user' | 'assistant';
    content: string;
}

/** An inline action card the coach authored, mirroring the /chat payload. */
export interface CoachCard {
    type: string;
    intention: IntentionData;
}

/** The coach's response to one chat turn. */
export interface CoachReply {
    message: string;
    cards: CoachCard[];
}

/**
 * The seam between the chat UI and the server. The hook depends only on this
 * interface, so tests inject a fake and the live screen uses the HTTP client
 * below. Keeps "AI authors, UI renders" — the client only ferries data.
 */
export interface CoachClient {
    sendMessage(message: string, history: CoachTurn[]): Promise<CoachReply>;
    logOutcome(
        actionId: number,
        outcome: LogOutcome,
        reason?: string,
    ): Promise<void>;
}

/** Laravel sets an XSRF-TOKEN cookie; echo it back as the CSRF header. */
function csrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function post(
    url: string,
    body: Record<string, unknown>,
): Promise<Response> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error(`Request to ${url} failed: ${response.status}`);
    }

    return response;
}

/** The production client — talks to the session-authenticated web routes. */
export const httpCoachClient: CoachClient = {
    async sendMessage(message, history) {
        const response = await post('/chat', { message, history });
        const data = (await response.json()) as Partial<CoachReply>;

        return {
            message: data.message ?? '',
            cards: data.cards ?? [],
        };
    },

    async logOutcome(actionId, outcome, reason) {
        await post(`/actions/${actionId}/logs`, {
            outcome,
            reason: reason ?? null,
        });
    },
};
