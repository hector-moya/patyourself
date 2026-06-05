/**
 * PatYourSelf — data layer (ported from the Claude Design handoff `data.js`).
 *
 * Mirrors the prototype's seed intentions, the pure derivations, and the
 * stubbed coach. Everything is in-memory for now; this is the single seam
 * that the real Laravel CoachService + Eloquent models replace later
 * (tasks 5/6/12). Keep `getCoachResponse` swappable — UI renders what it
 * returns, it never authors copy itself.
 */

export type Direction = 'build' | 'eliminate';
export type Outcome = 'done' | 'urge-survived' | 'skipped';
export type StrategyStatus = 'active' | 'failed' | 'succeeded' | 'abandoned';

export interface Strategy {
    version: number;
    action: string;
    status: StrategyStatus;
    reason: string;
    createdAt: string; // YYYY-MM-DD
}

export interface LogEntry {
    date: string; // YYYY-MM-DD
    outcome: Outcome;
}

export interface Loop {
    cue: string;
    craving: string;
    response: string;
    reward: string;
}

export interface Intention {
    id: string;
    title: string;
    direction: Direction;
    loop: Loop;
    strategies: Strategy[];
    log: LogEntry[];
}

/** A coach turn renders as one or more thread items keyed by `kind`. */
export interface CoachAction {
    label: string;
    intent: string;
    payload?: string;
}
export type ThreadItem =
    | { kind: 'coach'; sig?: string; html: string; actions?: CoachAction[] }
    | { kind: 'user'; text: string }
    | { kind: 'action-card'; intentionId: string }
    | { kind: 'reason-chips'; intentionId: string };

// ── Date helpers ────────────────────────────────────────
export const today = new Date();
export const ymd = (d: Date): string => d.toISOString().slice(0, 10);
const daysAgo = (n: number): string => {
    const d = new Date(today);
    d.setDate(d.getDate() - n);

    return ymd(d);
};

// ── Seed intentions ─────────────────────────────────────
export const SEED_INTENTIONS: Intention[] = [
    {
        id: 'i_gym',
        title: 'Go to gym',
        direction: 'build',
        loop: {
            cue: 'Alarm goes off at 6:30',
            craving: '"I\'d rather sleep ten more minutes."',
            response: 'Get up, drive to gym, lift.',
            reward: 'Energy. Done before the day starts.',
        },
        strategies: [
            {
                version: 1,
                action: 'Put on shoes and walk out the door',
                status: 'failed',
                reason: "Too tired — couldn't get past the bed.",
                createdAt: daysAgo(21),
            },
            {
                version: 2,
                action: 'Set alarm 30 min earlier (6:30)',
                status: 'failed',
                reason: 'Snoozed through it three days in a row.',
                createdAt: daysAgo(14),
            },
            {
                version: 3,
                action: 'Lay out gym clothes on the chair the night before',
                status: 'active',
                reason: 'Removes the morning decision.',
                createdAt: daysAgo(7),
            },
        ],
        log: [
            { date: daysAgo(6), outcome: 'skipped' },
            { date: daysAgo(5), outcome: 'done' },
            { date: daysAgo(4), outcome: 'done' },
            { date: daysAgo(3), outcome: 'done' },
            { date: daysAgo(2), outcome: 'done' },
            { date: daysAgo(1), outcome: 'done' },
        ],
    },
    {
        id: 'i_smoke',
        title: 'Quit smoking',
        direction: 'eliminate',
        loop: {
            cue: "After lunch, walking past the smokers' bench",
            craving: '"A cigarette would settle me right now."',
            response: 'Light up.',
            reward: 'A nicotine hit. A break.',
        },
        strategies: [
            {
                version: 1,
                action: 'Drink a glass of water after lunch instead',
                status: 'active',
                reason: 'Replace the ritual, not the moment.',
                createdAt: daysAgo(10),
            },
        ],
        log: [
            { date: daysAgo(6), outcome: 'urge-survived' },
            { date: daysAgo(5), outcome: 'skipped' },
            { date: daysAgo(4), outcome: 'urge-survived' },
            { date: daysAgo(3), outcome: 'skipped' },
            { date: daysAgo(2), outcome: 'urge-survived' },
            { date: daysAgo(1), outcome: 'urge-survived' },
        ],
    },
    {
        id: 'i_wake',
        title: 'Wake up early',
        direction: 'build',
        loop: {
            cue: 'First alarm at 6:00',
            craving: '"Five more minutes won\'t hurt."',
            response: 'Feet on the floor within 30s.',
            reward: 'A quiet hour before everyone else.',
        },
        strategies: [
            {
                version: 1,
                action: 'Put phone across the room',
                status: 'active',
                reason: 'Forces me to stand up to turn it off.',
                createdAt: daysAgo(3),
            },
        ],
        log: [
            { date: daysAgo(2), outcome: 'done' },
            { date: daysAgo(1), outcome: 'skipped' },
        ],
    },
];

// ── Derivations (computed, not stored) ─────────────────
export function activeStrategy(it: Intention): Strategy | undefined {
    return it.strategies
        .slice()
        .reverse()
        .find((s) => s.status === 'active');
}
export function currentStreak(it: Intention): number {
    let streak = 0;

    for (let i = it.log.length - 1; i >= 0; i--) {
        const o = it.log[i].outcome;

        if (o === 'done' || o === 'urge-survived') {
            streak++;
        } else {
            break;
        }
    }

    return streak;
}
export function patternFlag(
    it: Intention,
): 'stack-ready' | 'needs-restrategy' | null {
    if (currentStreak(it) >= 5) {
        return 'stack-ready';
    }

    const recent = it.log.slice(-5).filter((l) => l.outcome === 'skipped');

    if (recent.length >= 3) {
        return 'needs-restrategy';
    }

    return null;
}
export function todayLog(it: Intention): LogEntry | null {
    return it.log.find((l) => l.date === ymd(today)) || null;
}
export function countDoneToday(intentions: Intention[]): number {
    return intentions.filter((i) => {
        const t = todayLog(i);

        return !!t && (t.outcome === 'done' || t.outcome === 'urge-survived');
    }).length;
}

// ── Stubbed coach. Markup-safe canned responses. ───────
interface CoachCtx {
    intention?: Intention;
    intentions?: Intention[];
    reason?: string;
    proposalText?: string;
    proposal?: string;
    value?: string;
}

export function getCoachResponse(
    intent: string,
    ctx: CoachCtx = {},
): ThreadItem[] {
    const it = ctx.intention;

    switch (intent) {
        case 'morning_greet':
            return [
                {
                    kind: 'coach',
                    sig: 'good morning',
                    html: 'Three loops today. Want to go one by one, or just give me a quick all-clear?',
                },
            ];

        case 'show_card':
            return it ? [{ kind: 'action-card', intentionId: it.id }] : [];

        case 'logged_done': {
            if (!it) {
                return [];
            }

            const streak = currentStreak({
                ...it,
                log: [...it.log, { date: ymd(today), outcome: 'done' }],
            });

            if (streak >= 5) {
                return [
                    {
                        kind: 'coach',
                        sig: 'pattern noticed',
                        html: `That's <b>${streak} in a row</b> on <em>${it.title.toLowerCase()}</em>. The current strategy is sticking. Want to stack a small new habit onto it — say a 5-minute stretch after?`,
                        actions: [
                            {
                                label: 'Yes, propose one',
                                intent: 'propose_stack',
                            },
                            { label: 'Not yet', intent: 'dismiss' },
                        ],
                    },
                ];
            }

            return [
                {
                    kind: 'coach',
                    sig: 'logged',
                    html: `Logged. Nice — that's <b>${streak}</b> ${streak === 1 ? 'day' : 'days'} running.`,
                },
            ];
        }

        case 'logged_urge':
            if (!it) {
                return [];
            }

            return [
                {
                    kind: 'coach',
                    sig: 'logged',
                    html: `Surviving the urge counts twice. That's the real work on <em>${it.title.toLowerCase()}</em>.`,
                },
            ];

        case 'logged_skip_ask_reason':
            if (!it) {
                return [];
            }

            return [
                {
                    kind: 'coach',
                    sig: 'no blame',
                    html: 'Okay. What got in the way?',
                },
                { kind: 'reason-chips', intentionId: it.id },
            ];

        case 'reason_received_propose_strategy': {
            const r = (ctx.reason || '').toLowerCase();
            let proposal = 'Shrink it. Try a 30-second version tomorrow.';

            if (r.includes('tired')) {
                proposal =
                    'Tiredness keeps winning the morning. Want to flip this to evenings instead?';
            }

            if (r.includes('time')) {
                proposal =
                    'Time is the cost. Want to halve the action — make it doable in under 2 minutes?';
            }

            if (r.includes('forgot')) {
                proposal =
                    'Pair it with something you already do — right after brushing teeth, for example.';
            }

            if (r.includes('feel')) {
                proposal =
                    "That's worth naming. Sometimes the strategy is fine and the moment isn't — want to schedule a check-in tomorrow at the same cue?";
            }

            return [
                {
                    kind: 'coach',
                    sig: 'iterating',
                    html: `Got it — <em>"${ctx.reason}"</em>. ${proposal}`,
                    actions: [
                        {
                            label: 'Try that',
                            intent: 'accept_strategy',
                            payload: ctx.proposalText || 'Try evenings instead',
                        },
                        { label: 'Something else', intent: 'open_setup' },
                    ],
                },
            ];
        }

        case 'strategy_accepted':
            return [
                {
                    kind: 'coach',
                    sig: 'logged change',
                    html: "New strategy added. We'll track it from today. No pressure — first try is always rough.",
                },
            ];

        case 'typed_general':
            return [
                {
                    kind: 'coach',
                    sig: 'reading',
                    html: 'Heard. Want me to pull up a specific loop, or keep talking?',
                },
            ];

        default:
            return [{ kind: 'coach', sig: 'thinking', html: 'Mm.' }];
    }
}

// Default reason chips for the skip diagnostic
export const REASON_CHIPS = [
    'Too tired',
    'No time',
    'Forgot',
    "Didn't feel like it",
    'Other',
];

// Reply chip suggestions above the composer
export const REPLY_CHIPS = ['Done all', 'Struggled', 'Skip today'];
