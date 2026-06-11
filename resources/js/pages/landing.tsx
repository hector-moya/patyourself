import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    Bell,
    Flame,
    Heart,
    Leaf,
    Play,
    Route,
    Sparkles,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { Avatar, Eyebrow } from '@/patyourself/primitives';

/**
 * PatYourSelf — public landing page. Branded with the warm DS; routes to
 * Fortify login/register. The app itself lives behind auth at /dashboard.
 *
 * Composed straight from the patyourself design system (warm paper, clay-coral
 * primary, the cue→craving→response→reward loop motif) so it reads as the same
 * calm coach the product is. Sections: hero, the loop anatomy, a sample coach
 * conversation, why it's different, closing CTA.
 */

type LoopStage = {
    name: string;
    desc: string;
    icon: LucideIcon;
    accent: string;
    soft: string;
};

const LOOP_STAGES: LoopStage[] = [
    {
        name: 'Cue',
        desc: 'The trigger that starts it — a time, a place, a feeling you already have.',
        icon: Bell,
        accent: 'var(--cue)',
        soft: 'var(--cue-soft)',
    },
    {
        name: 'Craving',
        desc: 'The pull underneath — what you actually want the habit to give you.',
        icon: Flame,
        accent: 'var(--craving)',
        soft: 'var(--craving-soft)',
    },
    {
        name: 'Response',
        desc: 'The action itself. We keep it small enough that it almost always happens.',
        icon: Play,
        accent: 'var(--response)',
        soft: 'var(--response-soft)',
    },
    {
        name: 'Reward',
        desc: "The payoff that tells your brain it's worth doing again tomorrow.",
        icon: Sparkles,
        accent: 'var(--reward)',
        soft: 'var(--reward-soft)',
    },
];

type Feature = {
    title: string;
    body: string;
    icon: LucideIcon;
};

const FEATURES: Feature[] = [
    {
        title: 'Calm over loud',
        body: 'No confetti, no streak-fire, no guilt-red. Warm paper and quiet type — an interface that lowers your heart rate instead of spiking it.',
        icon: Leaf,
    },
    {
        title: 'Show the system, not a score',
        body: 'You see the mechanics of a habit — the loop and where to intervene — rather than one number that only makes you feel behind.',
        icon: Route,
    },
    {
        title: 'Every miss is data, not shame',
        body: "A day that didn't happen isn't a broken streak. The coach asks why, and turns the reason into your next, smarter strategy.",
        icon: Heart,
    },
];

function CoachMsg({
    children,
    actions,
}: {
    children: ReactNode;
    actions?: string[];
}) {
    return (
        <div className="py-msg">
            <Avatar kind="coach" size={32} />
            <div className="py-msg__col">
                <div className="py-bubble py-bubble--coach">
                    {children}
                    {actions && (
                        <div className="bubble-actions">
                            {actions.map((a) => (
                                <span key={a} className="py-chip py-chip--btn">
                                    {a}
                                </span>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

function UserMsg({ children }: { children: ReactNode }) {
    return (
        <div className="py-msg py-msg--user">
            <div className="py-msg__col">
                <div className="py-bubble py-bubble--user">{children}</div>
            </div>
        </div>
    );
}

export default function Landing() {
    return (
        <div className="py-landing" data-theme="light">
            <Head title="patyourself — a coach for your habits" />

            <header className="py-landing__bar">
                <span className="py-landing__brand">
                    <img src="/patyourself/app-icon.svg" alt="" />
                    <b>patyourself</b>
                </span>
                <nav className="py-landing__navlinks">
                    <Link
                        href="/login"
                        className="py-btn py-btn--ghost py-btn--sm"
                    >
                        Log in
                    </Link>
                    <Link
                        href="/register"
                        className="py-btn py-btn--secondary py-btn--sm"
                    >
                        Create account
                    </Link>
                </nav>
            </header>

            <main className="py-landing__main">
                {/* ---------- Hero ---------- */}
                <section className="py-landing__hero">
                    <Eyebrow>A coach, not a tracker</Eyebrow>
                    <h1 className="ds-display">
                        Change the loop,
                        <br />
                        not just the streak.
                    </h1>
                    <p className="ds-lead py-landing__lead">
                        Every habit is a loop — cue, craving, response, reward.
                        patyourself coaches you through one small change at a
                        time, and reworks the plan when life gets in the way.
                    </p>

                    <div className="py-landing__cta">
                        <Link
                            href="/register"
                            className="py-btn py-btn--primary py-btn--md"
                        >
                            Get started
                        </Link>
                        <Link
                            href="/login"
                            className="py-btn py-btn--secondary py-btn--md"
                        >
                            I already have an account
                        </Link>
                    </div>

                    <div className="py-landing__loops">
                        <span className="py-chip py-chip--cue">Cue</span>
                        <span className="py-landing__arrow">
                            <ArrowRight size={16} strokeWidth={2} />
                        </span>
                        <span className="py-chip py-chip--craving">
                            Craving
                        </span>
                        <span className="py-landing__arrow">
                            <ArrowRight size={16} strokeWidth={2} />
                        </span>
                        <span className="py-chip py-chip--response">
                            Response
                        </span>
                        <span className="py-landing__arrow">
                            <ArrowRight size={16} strokeWidth={2} />
                        </span>
                        <span className="py-chip py-chip--reward">Reward</span>
                    </div>
                </section>

                {/* ---------- The loop anatomy ---------- */}
                <section className="py-landing__section">
                    <div className="py-landing__sec-head">
                        <Eyebrow>The habit loop</Eyebrow>
                        <h2 className="ds-h2">
                            Every habit runs the same four beats.
                        </h2>
                        <p className="ds-lead">
                            Most apps count the reward and ignore the rest. We
                            map the whole loop, then find the one beat worth
                            changing.
                        </p>
                    </div>

                    <div className="py-loopflow">
                        {LOOP_STAGES.map((stage, i) => {
                            const StageIcon = stage.icon;

                            return (
                                <div
                                    key={stage.name}
                                    className="py-loopstep"
                                    style={
                                        {
                                            '--accent': stage.accent,
                                            '--accent-soft': stage.soft,
                                        } as CSSProperties
                                    }
                                >
                                    <span className="py-loopstep__num">{`0${i + 1}`}</span>
                                    <span className="py-loopstep__icon">
                                        <StageIcon size={22} strokeWidth={2} />
                                    </span>
                                    <span className="py-loopstep__name">
                                        {stage.name}
                                    </span>
                                    <span className="py-loopstep__desc">
                                        {stage.desc}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                </section>

                {/* ---------- Sample conversation ---------- */}
                <section className="py-landing__section">
                    <div className="py-landing__convo-wrap">
                        <div className="py-landing__convo-copy">
                            <Eyebrow>The conversation is the product</Eyebrow>
                            <h2 className="ds-h2" style={{ marginTop: 12 }}>
                                It coaches. It doesn&rsquo;t nag.
                            </h2>
                            <p className="ds-lead" style={{ marginTop: 14 }}>
                                You talk, it listens, and it anchors small
                                changes to cues you already have. When a day
                                doesn&rsquo;t happen, it asks why — and uses the
                                answer to make the next version easier.
                            </p>
                        </div>

                        <div className="py-convo" aria-hidden="true">
                            <div className="py-convo__head">
                                <Avatar kind="coach" size={32} />
                                <span className="py-convo__who">
                                    <b>your coach</b>
                                    <span className="py-convo__sub">
                                        Building a loop
                                    </span>
                                </span>
                            </div>
                            <div className="py-convo__body">
                                <CoachMsg
                                    actions={[
                                        'Set coffee as my cue',
                                        'Pick a different cue',
                                    ]}
                                >
                                    Coffee at 7am sounds like a strong cue — it
                                    already happens every day without thinking.
                                    Want to anchor your two-minute journal to
                                    it?
                                </CoachMsg>
                                <UserMsg>
                                    Let&rsquo;s anchor it to coffee.
                                </UserMsg>
                                <CoachMsg>
                                    Nice — that&rsquo;s your cue locked in.
                                    We&rsquo;ll keep the response tiny on
                                    purpose: two minutes, then you&rsquo;re
                                    done.
                                </CoachMsg>
                            </div>
                        </div>
                    </div>
                </section>

                {/* ---------- Why it's different ---------- */}
                <section className="py-landing__section">
                    <div className="py-landing__sec-head">
                        <Eyebrow>Why it feels different</Eyebrow>
                        <h2 className="ds-h2">
                            Built like a good therapist&rsquo;s room.
                        </h2>
                        <p className="ds-lead">
                            Warm, focused, on your side — not a dopamine slot
                            machine dressed up as self-improvement.
                        </p>
                    </div>

                    <div className="py-feature-grid">
                        {FEATURES.map((f) => {
                            const FeatureIcon = f.icon;

                            return (
                                <div key={f.title} className="py-feature">
                                    <span className="py-feature__icon">
                                        <FeatureIcon
                                            size={22}
                                            strokeWidth={2}
                                        />
                                    </span>
                                    <span className="py-feature__title">
                                        {f.title}
                                    </span>
                                    <span className="py-feature__body">
                                        {f.body}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                </section>

                {/* ---------- Closing CTA ---------- */}
                <section className="py-landing__closer">
                    <div className="py-landing__closer-card">
                        <Eyebrow>Start small</Eyebrow>
                        <h2 className="ds-display">
                            Start with one small loop.
                        </h2>
                        <p className="ds-lead">
                            Pick a single habit. Your coach takes it from there
                            — one beat at a time.
                        </p>
                        <div className="py-landing__cta">
                            <Link
                                href="/register"
                                className="py-btn py-btn--primary py-btn--md"
                            >
                                Get started
                            </Link>
                        </div>
                    </div>
                </section>
            </main>

            <footer className="py-landing__foot">
                <div className="py-landing__foot-inner">
                    <span className="py-landing__foot-brand">
                        <img src="/patyourself/app-icon.svg" alt="" />
                        <b>patyourself</b>
                        <span className="py-landing__foot-tag">
                            a coach for your habits
                        </span>
                    </span>
                    <nav className="py-landing__foot-links">
                        <Link href="/login">Log in</Link>
                        <Link href="/register">Create account</Link>
                    </nav>
                </div>
            </footer>
        </div>
    );
}
