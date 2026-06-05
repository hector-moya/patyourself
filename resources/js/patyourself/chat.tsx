/**
 * PatYourSelf — conversation pieces. CoachBubble (renders the coach's
 * model HTML + inline action chips), UserBubble, SystemLine, TypingIndicator,
 * DayCard (the daily-log card — the daily driver), ReasonChips, ReplyChips.
 */
import { useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import { REASON_CHIPS } from './data';
import type { CoachAction, Intention, Outcome } from './data';
import { Avatar, Chip, Composer, Icon } from './primitives';

export function CoachBubble({
    html,
    actions,
    onAction,
    time,
}: {
    html?: string;
    actions?: CoachAction[];
    onAction?: (a: CoachAction) => void;
    time?: string;
}) {
    return (
        <div className="py-msg py-msg--coach">
            <Avatar kind="coach" size={34} />
            <div className="py-msg__col">
                <div className="py-bubble py-bubble--coach">
                    {html && (
                        <span dangerouslySetInnerHTML={{ __html: html }} />
                    )}
                    {actions && actions.length > 0 && (
                        <div className="bubble-actions">
                            {actions.map((a, i) => (
                                <Chip
                                    key={i}
                                    active={i === 0}
                                    onClick={() => onAction?.(a)}
                                >
                                    {a.label}
                                </Chip>
                            ))}
                        </div>
                    )}
                </div>
                {time && <div className="py-msg__time">{time}</div>}
            </div>
        </div>
    );
}

export function UserBubble({
    text,
    children,
    time,
}: {
    text?: string;
    children?: ReactNode;
    time?: string;
}) {
    return (
        <div className="py-msg py-msg--user">
            <div className="py-msg__col">
                <div className="py-bubble py-bubble--user">
                    {text || children}
                </div>
                {time && <div className="py-msg__time">{time}</div>}
            </div>
        </div>
    );
}

export function TypingIndicator() {
    return (
        <div className="py-msg py-msg--coach">
            <Avatar kind="coach" size={34} />
            <div
                className="py-bubble py-bubble--coach py-typing"
                aria-label="Coach is typing"
            >
                <span />
                <span />
                <span />
            </div>
        </div>
    );
}

export function SystemLine({ children }: { children: ReactNode }) {
    return (
        <div className="py-systemline">
            <span>{children}</span>
        </div>
    );
}

/* ---- Daily log card (one per intention; the daily driver) ---- */
export const OUTCOME_META: Record<
    Outcome,
    { label: string; text: string; icon: string; klass: string }
> = {
    done: { label: 'done', text: 'Did it', icon: 'check', klass: 'did' },
    'urge-survived': {
        label: 'urge survived',
        text: 'Urge survived',
        icon: 'shield-check',
        klass: 'urge',
    },
    skipped: {
        label: "didn't happen",
        text: "Didn't happen",
        icon: 'minus',
        klass: 'skip',
    },
};

export function DayCard({
    intention,
    todaysAction,
    logged,
    onLog,
}: {
    intention: Intention;
    todaysAction: string;
    logged: Outcome | null;
    onLog: (o: Outcome) => void;
}) {
    const isBuild = intention.direction === 'build';

    if (logged) {
        const m = OUTCOME_META[logged] || OUTCOME_META.done;
        const isWin = logged === 'done' || logged === 'urge-survived';

        return (
            <div className={`daycard daycard--logged${isWin ? '' : 'is-miss'}`}>
                <span className="daycard__check">
                    <Icon name={isWin ? 'check' : 'minus'} size={16} />
                </span>
                <div className="daycard__loggedtext">
                    <b>{intention.title}</b>
                    <span>Logged as {m.label}</span>
                </div>
            </div>
        );
    }

    return (
        <div
            className="daycard"
            style={{ '--accent': 'var(--response)' } as CSSProperties}
        >
            <div className="daycard__head">
                <span
                    className={`daycard__dir daycard__dir--${intention.direction}`}
                >
                    <Icon
                        name={isBuild ? 'trending-up' : 'trending-down'}
                        size={13}
                    />
                    {isBuild ? 'build' : 'eliminate'}
                </span>
                <span className="daycard__today">today</span>
            </div>
            <div className="daycard__title">{intention.title}</div>
            <div className="daycard__action">{todaysAction}</div>
            <div className="daycard__opts">
                <button
                    type="button"
                    className="daycard__opt did"
                    onClick={() => onLog('done')}
                >
                    <Icon name="check" size={18} /> Did it
                </button>
                <button
                    type="button"
                    className="daycard__opt urge"
                    onClick={() => onLog('urge-survived')}
                >
                    <Icon
                        name={isBuild ? 'footprints' : 'shield-check'}
                        size={18}
                    />{' '}
                    {isBuild ? 'Showed up' : 'Urge survived'}
                </button>
                <button
                    type="button"
                    className="daycard__opt skip"
                    onClick={() => onLog('skipped')}
                >
                    <Icon name="minus" size={18} />{' '}
                    {isBuild ? 'Skipped' : 'Slipped'}
                </button>
            </div>
        </div>
    );
}

/* ---- Reason chips (inline diagnostic after a miss) ---- */
export function ReasonChips({ onPick }: { onPick: (reason: string) => void }) {
    const [showInput, setShowInput] = useState(false);
    const [custom, setCustom] = useState('');

    return (
        <div className="py-msg py-msg--coach" style={{ marginTop: 2 }}>
            <span style={{ width: 34, flex: 'none' }} />
            <div className="py-msg__col" style={{ maxWidth: '82%' }}>
                <div className="reply-chips">
                    {REASON_CHIPS.map((c) => (
                        <Chip
                            key={c}
                            onClick={() =>
                                c === 'Other' ? setShowInput(true) : onPick(c)
                            }
                        >
                            {c}
                        </Chip>
                    ))}
                </div>
                {showInput && (
                    <div
                        style={{
                            display: 'flex',
                            gap: 8,
                            marginTop: 4,
                            width: 320,
                        }}
                    >
                        <Composer
                            placeholder="In your own words…"
                            value={custom}
                            onChange={setCustom}
                            onSend={(v) => onPick(v)}
                        />
                    </div>
                )}
            </div>
        </div>
    );
}

/* ---- Reply chips above the composer ---- */
export function ReplyChips({
    chips,
    onPick,
}: {
    chips: string[];
    onPick: (c: string) => void;
}) {
    return (
        <div className="reply-chips">
            {chips.map((c) => (
                <Chip key={c} onClick={() => onPick(c)}>
                    {c}
                </Chip>
            ))}
        </div>
    );
}
