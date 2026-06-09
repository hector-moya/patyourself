import { Link } from '@inertiajs/react';
import { Check, SkipForward, X } from 'lucide-react';

import { cn } from '@/lib/utils';
import type { IntentionData, LogOutcome } from '@/patyourself/types';

/**
 * The action card — the app's foundational "AI authors, UI renders" surface. It
 * takes one LLM-authored Intention and presents it as an interactive loop card:
 * a habit-anatomy summary (cue → response → reward), the active strategy's
 * tactic, quick-log buttons, and a link into the full loop detail.
 *
 * Logging is opt-in: pass `onLog` to surface the quick-log row (the chat thread
 * wires it to the API in Task 22). Without it the card is purely presentational,
 * so the same component renders safely anywhere a loop needs to be shown.
 */
export function ActionCard({
    intention,
    onLog,
}: {
    intention: IntentionData;
    onLog?: (outcome: LogOutcome) => void;
}) {
    const tactic = intention.strategy?.approach ?? intention.response;

    return (
        <article className="w-full max-w-[85%] rounded-2xl border border-border bg-card p-4 text-left shadow-sm">
            <header className="flex items-start justify-between gap-2">
                <h3 className="font-semibold text-foreground">
                    {intention.title}
                </h3>
                <div className="flex shrink-0 items-center gap-1">
                    <Badge tone="solid">
                        {intention.type === 'break' ? 'Break' : 'Build'}
                    </Badge>
                    <Badge>{intention.status}</Badge>
                </div>
            </header>

            <dl className="mt-3 flex flex-col gap-1.5">
                <AnatomyRow label="When" value={intention.cue} />
                <AnatomyRow label="I'll" value={intention.response} />
                <AnatomyRow label="To get" value={intention.reward} />
            </dl>

            <p className="mt-3 rounded-xl bg-muted px-3 py-2 text-sm text-foreground">
                {tactic}
            </p>

            {onLog && (
                <div className="mt-3 grid grid-cols-3 gap-2">
                    <LogButton
                        icon={<Check className="size-4" />}
                        label="Done"
                        tone="positive"
                        onClick={() => onLog('completed')}
                    />
                    <LogButton
                        icon={<X className="size-4" />}
                        label="Missed"
                        tone="negative"
                        onClick={() => onLog('failed')}
                    />
                    <LogButton
                        icon={<SkipForward className="size-4" />}
                        label="Skip"
                        tone="neutral"
                        onClick={() => onLog('skipped')}
                    />
                </div>
            )}

            <Link
                href={`/intentions/${intention.id}`}
                className="mt-3 inline-block text-xs font-medium text-primary hover:underline"
            >
                View loop →
            </Link>
        </article>
    );
}

function AnatomyRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex gap-2 text-sm">
            <dt className="w-14 shrink-0 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="flex-1 text-foreground">{value}</dd>
        </div>
    );
}

const TONE_STYLES: Record<'positive' | 'negative' | 'neutral', string> = {
    positive:
        'border-emerald-500/30 text-emerald-700 hover:bg-emerald-500/10 dark:text-emerald-400',
    negative:
        'border-rose-500/30 text-rose-700 hover:bg-rose-500/10 dark:text-rose-400',
    neutral: 'border-border text-muted-foreground hover:bg-accent',
};

function LogButton({
    icon,
    label,
    tone,
    onClick,
}: {
    icon: React.ReactNode;
    label: string;
    tone: 'positive' | 'negative' | 'neutral';
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'flex items-center justify-center gap-1.5 rounded-lg border py-2 text-xs font-medium transition-colors',
                TONE_STYLES[tone],
            )}
        >
            {icon}
            {label}
        </button>
    );
}

function Badge({
    children,
    tone = 'outline',
}: {
    children: string;
    tone?: 'outline' | 'solid';
}) {
    return (
        <span
            className={cn(
                'rounded-full px-2 py-0.5 text-xs capitalize',
                tone === 'solid'
                    ? 'bg-primary/10 font-medium text-primary'
                    : 'border border-border text-muted-foreground',
            )}
        >
            {children}
        </span>
    );
}
