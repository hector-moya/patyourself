import { Head, Link } from '@inertiajs/react';
import { Bell, ChevronDown, Flame, Play, Sparkles } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

/**
 * PatYourSelf — public landing page. Ported from the design-system handoff
 * (landing/Patyourself Landing.html): a flat warm hero, then the habit-loop
 * breakdown. Routes to Fortify login/register; the app itself lives behind
 * auth at /dashboard.
 *
 * The hero used to run a Three.js field of 24,000 points with rings rippling
 * outward from wherever you tapped, and a counter that read them back. It was
 * removed: the page is the only thing in the app that used Three, and it was
 * paying for a 3D engine — the largest chunk in the whole bundle, larger than
 * the app itself — to draw expanding circles behind a paragraph. The deploy
 * builds on a one-core box that also serves four other sites, and that made
 * the cost concrete rather than theoretical.
 *
 * What is left is the hero the page already showed to anyone whose browser
 * refused a WebGL context, which is why nothing here needed designing.
 */

type Stage = {
    key: string;
    icon: LucideIcon;
    name: string;
    num: string;
    text: string;
};

const STAGES: Stage[] = [
    {
        key: 'cue',
        icon: Bell,
        name: 'Cue',
        num: '01',
        text: 'The signal that starts it — coffee at 7am, keys in the bowl.',
    },
    {
        key: 'craving',
        icon: Flame,
        name: 'Craving',
        num: '02',
        text: 'The pull behind it. What you actually want.',
    },
    {
        key: 'response',
        icon: Play,
        name: 'Response',
        num: '03',
        text: 'The action — shrunk until it takes two minutes.',
    },
    {
        key: 'reward',
        icon: Sparkles,
        name: 'Reward',
        num: '04',
        text: 'The payoff. A quiet pat on the back.',
    },
];

function LoopSection() {
    return (
        <section className="loop-section" id="how">
            <div className="loop-section__inner">
                <div className="loop-section__head">
                    <p className="ds-eyebrow hero__eyebrow">How it works</p>
                    <h2 className="ds-h2">
                        Four small steps. Then again, and again.
                    </h2>
                    <p className="ds-lead">
                        patyourself deconstructs every habit into its loop,
                        finds where yours breaks, and helps you roll it one
                        notch further each day.
                    </p>
                </div>

                <div className="loop-grid">
                    {STAGES.map((s) => {
                        const StageIcon = s.icon;

                        return (
                            <article
                                key={s.key}
                                className={`stage-card stage-card--${s.key}`}
                            >
                                <span className="stage-card__num">{s.num}</span>
                                <span className="stage-card__icon">
                                    <StageIcon size={20} strokeWidth={2} />
                                </span>
                                <h3>{s.name}</h3>
                                <p>{s.text}</p>
                            </article>
                        );
                    })}
                </div>

                <blockquote className="loop-quote">
                    &ldquo;One must imagine the habit-builder happy.&rdquo;
                    <cite>after Albert Camus</cite>
                </blockquote>

                <div className="loop-section__cta">
                    <Link
                        href="/register"
                        className="py-btn py-btn--primary py-btn--md"
                    >
                        Start your first loop
                    </Link>
                    <span className="loop-section__footer">
                        patyourself — progress, not perfection
                    </span>
                </div>
            </div>
        </section>
    );
}

export default function Landing() {
    const scrollToHow = (e: React.MouseEvent<HTMLAnchorElement>) => {
        e.preventDefault();
        document
            .getElementById('how')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    return (
        <div className="py-landing" data-theme="light">
            <Head title="patyourself — progress, not perfection" />

            <section className="hero">
                <header className="site-header">
                    <Link
                        className="site-header__brand"
                        href="/"
                        aria-label="patyourself home"
                    >
                        <img src="/patyourself/app-icon.svg" alt="" />
                        <b>patyourself</b>
                    </Link>
                    <nav className="site-header__actions">
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

                <div className="hero__inner">
                    <div className="hero__copy">
                        <p className="ds-eyebrow hero__eyebrow">
                            A lab notebook, not a tracker
                        </p>
                        <h1 className="hero__title">
                            <em>Evidence,</em>
                            <br />
                            not willpower.
                        </h1>
                        <p className="hero__lead">
                            Every habit is a loop — cue, craving, response,
                            reward. patyourself keeps the record: one experiment
                            at a time, in your own words, so you can read back
                            what actually happened when it went wrong.
                        </p>
                        <div className="hero__cta">
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
                    </div>
                </div>

                <a className="scroll-hint" href="#how" onClick={scrollToHow}>
                    how it works
                    <ChevronDown size={12} strokeWidth={2.5} />
                </a>
            </section>

            <LoopSection />
        </div>
    );
}
