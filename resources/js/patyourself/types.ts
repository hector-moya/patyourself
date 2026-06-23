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

/** The loggable action embedded in an IntentionResource (the card's quick-log target). */
export interface ActiveActionData {
    id: number;
    title: string;
    description: string | null;
    status: string;
    scheduled_for: string | null;
    recurrence: string | null;
    schedule_kind: 'clock' | 'anchored' | null;
    anchor: string | null;
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
    active_action?: ActiveActionData | null;
}

/**
 * The outcome a user records against an action. Mirrors ActionLog's
 * OUTCOME_* constants on the server (the only values LogAction accepts).
 */
export type LogOutcome = 'completed' | 'failed' | 'skipped';

/**
 * One item in the chat thread. Coach/user turns are text; a `card` turn renders
 * an inline action card from an LLM-authored Intention object. The same shape
 * carries both the loops seeded on load and the ones the coach authors live.
 */
export type ChatMessage =
    | { id: string; role: 'coach' | 'user'; text: string }
    | { id: string; role: 'card'; intention: IntentionData };

/** One stored turn from the server-side coach conversation (dashboard `thread` prop). */
export interface ThreadMessage {
    id: string;
    role: 'user' | 'coach';
    text: string;
}

/** One delivered cue in the inbox (mirrors InboxController's mapped payload). */
export interface NotificationData {
    id: string;
    type?: 'action_due' | 'strategy_revised';
    action_id: number | null;
    intention_id: number | null;
    title: string | null;
    fired_at: string | null;
    change_reason?: string | null;
    approach?: string | null;
    read_at: string | null;
}

/** One outcome mark in a progress sparkline. Mirrors ActionLog's OUTCOME_* values. */
export type OutcomeMark = 'completed' | 'failed' | 'skipped';

/** The active strategy's leading run (from OutcomeStreak), as shown on a progress card. */
export interface LoopStreak {
    outcome: 'completed' | 'failed' | null;
    length: number;
}

/** One active loop's metric card on the progress index (mirrors ProgressController@index). */
export interface LoopProgressCard {
    id: number;
    title: string;
    type: string;
    streak: LoopStreak;
    completion_rate: number | null; // 0–100, null when no decided logs
    totals: { completed: number; failed: number; skipped: number };
    recent: OutcomeMark[]; // oldest → newest, max 10
    last_logged_at: string | null;
    summary_excerpt: string | null;
}

/** The same metric block on the detail screen (no index-only excerpt). */
export type LoopProgressDetail = Omit<LoopProgressCard, 'summary_excerpt'>;

/** The per-user coach token usage block on the progress index (mirrors CoachUsageGuard::snapshotFor). */
export interface CoachUsageSnapshot {
    used: number;
    budget: number; // 0 or less = uncapped
    remaining: number | null; // null when uncapped
    breakdown: Record<string, number>; // purpose => tokens in the rolling 24h
}
