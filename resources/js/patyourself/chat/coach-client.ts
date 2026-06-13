import type { IntentionData, LogOutcome } from '@/patyourself/types';

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

export interface ReschedulePayload {
    kind: 'clock' | 'anchored';
    time?: string | null;
    recurrence?: string | null;
    anchor?: string | null;
}

/**
 * The seam between the chat UI and the server. The hook depends only on this
 * interface, so tests inject a fake and the live screen uses the HTTP client
 * below. Keeps "AI authors, UI renders" — the client only ferries data.
 *
 * History is stored server-side; the client sends only the current message.
 */
export interface CoachClient {
    sendMessage(message: string): Promise<CoachReply>;
    logOutcome(
        actionId: number,
        outcome: LogOutcome,
        reason?: string,
    ): Promise<void>;
    rescheduleAction(
        actionId: number,
        schedule: ReschedulePayload,
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

async function patch(
    url: string,
    body: Record<string, unknown>,
): Promise<Response> {
    const response = await fetch(url, {
        method: 'PATCH',
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
    async sendMessage(message) {
        const response = await post('/chat', { message });
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

    async rescheduleAction(actionId, schedule) {
        await patch(`/actions/${actionId}`, {
            kind: schedule.kind,
            time: schedule.time ?? null,
            recurrence: schedule.recurrence ?? null,
            anchor: schedule.anchor ?? null,
        });
    },
};
