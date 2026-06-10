import {
    act,
    render,
    renderHook,
    screen,
    waitFor,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import type {
    ChatMessage,
    IntentionData,
    LogOutcome,
} from '@/patyourself/types';
import { ChatThread, useChatThread } from './chat-home';
import type { CoachClient } from './coach-client';

function makeIntention(overrides: Partial<IntentionData> = {}): IntentionData {
    return {
        id: 1,
        title: 'Morning run',
        description: null,
        type: 'build',
        status: 'active',
        cue: 'Alarm at 6am',
        craving: 'Feel ahead',
        response: 'Run one block',
        reward: 'Coffee',
        metadata: null,
        created_at: null,
        updated_at: null,
        strategy: null,
        active_action: { id: 99, title: 'Run one block', status: 'active' },
        ...overrides,
    };
}

function fakeClient(overrides: Partial<CoachClient> = {}): CoachClient {
    return {
        sendMessage: vi.fn(async () => ({ message: 'Coach here.', cards: [] })),
        logOutcome: vi.fn(async () => {}),
        ...overrides,
    };
}

function texts(messages: ChatMessage[]): string[] {
    return messages.flatMap((m) => (m.role === 'card' ? [] : [m.text]));
}

describe('useChatThread', () => {
    it('appends the user message immediately on send', () => {
        const { result } = renderHook(() => useChatThread([], fakeClient()));

        act(() => result.current.send('hello coach'));

        expect(texts(result.current.messages)).toContain('hello coach');
    });

    it('appends the coach reply returned by the client', async () => {
        const client = fakeClient({
            sendMessage: vi.fn(async () => ({
                message: 'Got it — nice work.',
                cards: [],
            })),
        });
        const { result } = renderHook(() => useChatThread([], client));

        act(() => result.current.send('done my run'));

        await waitFor(() =>
            expect(texts(result.current.messages)).toContain(
                'Got it — nice work.',
            ),
        );
    });

    it('renders any cards the coach authors mid-conversation', async () => {
        const authored = makeIntention({ id: 7, title: 'Floss nightly' });
        const client = fakeClient({
            sendMessage: vi.fn(async () => ({
                message: 'Built you a loop.',
                cards: [{ type: 'intention', intention: authored }],
            })),
        });
        const { result } = renderHook(() => useChatThread([], client));

        act(() => result.current.send('help me floss'));

        await waitFor(() => {
            const card = result.current.messages.find((m) => m.role === 'card');
            expect(card).toBeDefined();
        });
    });

    it('sends prior turns as history', async () => {
        const send = vi.fn<CoachClient['sendMessage']>(async () => ({
            message: 'ok',
            cards: [],
        }));
        const client = fakeClient({ sendMessage: send });
        // One seeded greeting turn exists for a user with loops.
        const { result } = renderHook(() =>
            useChatThread([makeIntention()], client),
        );

        act(() => result.current.send('morning'));

        await waitFor(() => expect(send).toHaveBeenCalled());
        const [message, history] = send.mock.calls[0];
        expect(message).toBe('morning');
        expect(Array.isArray(history)).toBe(true);
        // The seeded coach greeting is carried as an assistant turn.
        expect(history.some((turn) => turn.role === 'assistant')).toBe(true);
    });

    it('logs an outcome through the client', async () => {
        const client = fakeClient();
        const { result } = renderHook(() => useChatThread([], client));

        await act(async () => {
            await result.current.log(makeIntention(), 'completed');
        });

        expect(client.logOutcome).toHaveBeenCalledWith(
            99,
            'completed',
            undefined,
        );
    });

    it('carries the reason when logging a failure', async () => {
        const client = fakeClient();
        const { result } = renderHook(() => useChatThread([], client));

        await act(async () => {
            await result.current.log(
                makeIntention(),
                'failed' satisfies LogOutcome,
                'overslept',
            );
        });

        expect(client.logOutcome).toHaveBeenCalledWith(
            99,
            'failed',
            'overslept',
        );
    });

    it('posts a coach acknowledgement turn after a successful log', async () => {
        const client = fakeClient();
        const { result } = renderHook(() => useChatThread([], client));

        await act(async () => {
            await result.current.log(makeIntention(), 'completed');
        });

        // The log is reflected back to the coach as a new chat turn.
        await waitFor(() => expect(client.sendMessage).toHaveBeenCalled());
    });

    it('does not log when the loop has no active action', async () => {
        const client = fakeClient();
        const { result } = renderHook(() => useChatThread([], client));

        await act(async () => {
            await result.current.log(
                makeIntention({ active_action: null }),
                'completed',
            );
        });

        expect(client.logOutcome).not.toHaveBeenCalled();
    });
});

function cardMessage(intention: IntentionData): ChatMessage {
    return { id: 'c1', role: 'card', intention };
}

describe('ChatThread quick-log wiring', () => {
    it('logs immediately when a completion is tapped', async () => {
        const intention = makeIntention();
        const onLog = vi.fn();
        render(
            <ChatThread messages={[cardMessage(intention)]} onLog={onLog} />,
        );

        await userEvent.click(screen.getByRole('button', { name: /done/i }));

        expect(onLog).toHaveBeenCalledWith(intention, 'completed', undefined);
    });

    it('asks for a reason before logging a failure', async () => {
        const intention = makeIntention();
        const onLog = vi.fn();
        render(
            <ChatThread messages={[cardMessage(intention)]} onLog={onLog} />,
        );

        await userEvent.click(screen.getByRole('button', { name: /missed/i }));
        // Logging is deferred until a reason is supplied.
        expect(onLog).not.toHaveBeenCalled();

        await userEvent.type(
            screen.getByRole('textbox', { name: /what got in the way/i }),
            'overslept',
        );
        await userEvent.click(screen.getByRole('button', { name: /save/i }));

        expect(onLog).toHaveBeenCalledWith(intention, 'failed', 'overslept');
    });

    it('omits quick-log buttons for a loop with no active action', () => {
        render(
            <ChatThread
                messages={[cardMessage(makeIntention({ active_action: null }))]}
                onLog={vi.fn()}
            />,
        );

        expect(
            screen.queryByRole('button', { name: /done/i }),
        ).not.toBeInTheDocument();
    });
});

describe('first-loop suggestions', () => {
    const greeting: ChatMessage = {
        id: 'g1',
        role: 'coach',
        text: "Let's build your first loop.",
    };

    it('offers starter suggestions when the thread has no loops yet', () => {
        render(<ChatThread messages={[greeting]} onSuggest={vi.fn()} />);

        expect(screen.getByText(/start your first loop/i)).toBeInTheDocument();
        expect(
            screen.getAllByRole('button', { name: /./ }).length,
        ).toBeGreaterThanOrEqual(3);
    });

    it('sends the picked suggestion to the coach', async () => {
        const onSuggest = vi.fn();
        render(<ChatThread messages={[greeting]} onSuggest={onSuggest} />);

        const [first] = screen.getAllByRole('button');
        await userEvent.click(first);

        expect(onSuggest).toHaveBeenCalledTimes(1);
        expect(typeof onSuggest.mock.calls[0][0]).toBe('string');
        expect(onSuggest.mock.calls[0][0].length).toBeGreaterThan(0);
    });

    it('disappears once the thread contains a loop card', () => {
        render(
            <ChatThread
                messages={[greeting, cardMessage(makeIntention())]}
                onSuggest={vi.fn()}
            />,
        );

        expect(
            screen.queryByText(/start your first loop/i),
        ).not.toBeInTheDocument();
    });

    it('is absent when no onSuggest handler is given', () => {
        render(<ChatThread messages={[greeting]} />);

        expect(
            screen.queryByText(/start your first loop/i),
        ).not.toBeInTheDocument();
    });
});
