/**
 * Client-side shapes mirroring the server API resources (IntentionResource,
 * StrategyResource). The LLM authors this data and the server validates it;
 * the UI only renders it.
 */

export interface StrategyData {
    id: number;
    version: number;
    status: string;
    intervention_point: string;
    approach: string;
    rationale: string | null;
    change_reason: string | null;
    superseded_reason: string | null;
    parent_strategy_id: number | null;
    metadata: Record<string, unknown> | null;
    created_at: string | null;
    updated_at: string | null;
}

/** The active-strategy summary embedded in an IntentionResource. */
export interface ActiveStrategySummary {
    intervention_point: string;
    approach: string;
    rationale: string | null;
    version: number;
}

export interface IntentionData {
    id: number;
    title: string;
    description: string | null;
    type: string;
    status: string;
    cue: string;
    craving: string;
    response: string;
    reward: string;
    metadata: Record<string, unknown> | null;
    created_at: string | null;
    updated_at: string | null;
    strategy?: ActiveStrategySummary | null;
}

/**
 * One item in the chat thread. Coach/user turns are text; a `card` turn renders
 * an inline action card from an LLM-authored Intention object. The same shape
 * carries both the loops seeded on load and the ones the coach authors live.
 */
export type ChatMessage =
    | { id: string; role: 'coach' | 'user'; text: string }
    | { id: string; role: 'card'; intention: IntentionData };
