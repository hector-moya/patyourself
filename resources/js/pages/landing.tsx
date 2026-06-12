import { Head, Link } from '@inertiajs/react';
import { Bell, ChevronDown, Flame, Play, Sparkles } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { initRippleField, loadThree } from '@/patyourself/ripple-field';
import type { RippleApi } from '@/patyourself/ripple-field';

/**
 * PatYourSelf — public landing page. Ported from the design-system handoff
 * (landing/Patyourself Landing.html): an interactive Three.js "ripples from one
 * small act" hero over the warm DS, then the habit-loop breakdown. Routes to
 * Fortify login/register; the app itself lives behind auth at /dashboard.
 *
 * Three.js is loaded from CDN at runtime (as the source design does) so the app
 * bundle gains no new dependency. If it fails to load, the hero degrades to the
 * static warm background — copy stays readable under the scrim.
 */

type PatEvent = CustomEvent<{ x: number; y: number; pats: number }>;

/** Floating "one small act" text that rises from each pat. */
function PatFloats() {
    const [floats, setFloats] = useState<
        Array<{ id: string; x: number; y: number }>
    >([]);

    useEffect(() => {
        let n = 0;
        const onPat = (ev: Event) => {
            const { detail } = ev as PatEvent;
            const id = `${++n}-${detail.x}-${detail.y}`;
            setFloats((f) => [...f, { id, x: detail.x, y: detail.y }]);
            window.setTimeout(
                () => setFloats((f) => f.filter((p) => p.id !== id)),
                950,
            );
        };
        window.addEventListener('py-pat', onPat);

        return () => window.removeEventListener('py-pat', onPat);
    }, []);

    return (
        <div aria-hidden="true">
            {floats.map((f) => (
                <span
                    key={f.id}
                    className="pat-float"
                    style={{ left: f.x, top: f.y }}
                >
                    one small act
                </span>
            ))}
        </div>
    );
}

/** The nudge + "ripples · NNN" counter, driven by the scene's pat events. */
function PatHint() {
    const [pats, setPats] = useState(0);

    useEffect(() => {
        const onPat = (ev: Event) => setPats((ev as PatEvent).detail.pats);
        const onReset = () => setPats(0);
        window.addEventListener('py-pat', onPat);
        window.addEventListener('py-pats-reset', onReset);

        return () => {
            window.removeEventListener('py-pat', onPat);
            window.removeEventListener('py-pats-reset', onReset);
        };
    }, []);

    const nudge =
        pats === 0
            ? 'tap the field — start one ripple'
            : pats < 4
              ? 'every small act ripples out'
              : 'progress, not perfection';

    return (
        <div className="pat-hint">
            <span className="pat-hint__nudge">{nudge}</span>
            <span className="pat-hint__count">
                ripples · {String(pats).padStart(3, '0')}
            </span>
        </div>
    );
}

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
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const apiRef = useRef<RippleApi | null>(null);

    /* Boot the ripple scene once Three.js is available (CDN). Degrade quietly. */
    useEffect(() => {
        let cancelled = false;

        loadThree()
            .then(() => {
                if (cancelled || !canvasRef.current || apiRef.current) {
                    return;
                }

                apiRef.current = initRippleField(canvasRef.current);
            })
            .catch(() => {
                /* no WebGL / offline — hero stays static, copy stays readable */
            });

        return () => {
            cancelled = true;
            apiRef.current?.dispose();
            apiRef.current = null;
        };
    }, []);

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
                <canvas
                    ref={canvasRef}
                    className="hero__canvas"
                    aria-label="A calm field of points; rings ripple outward from a single origin"
                />

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
                            A coach, not a tracker
                        </p>
                        <h1 className="hero__title">
                            <em>Progress,</em>
                            <br />
                            not perfection.
                        </h1>
                        <p className="hero__lead">
                            Every habit is a loop — cue, craving, response,
                            reward. patyourself coaches you through one small
                            change at a time, and reworks the plan when life
                            gets in the way.
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

                <PatHint />
                <a className="scroll-hint" href="#how" onClick={scrollToHow}>
                    how it works
                    <ChevronDown size={12} strokeWidth={2.5} />
                </a>
            </section>

            <LoopSection />
            <PatFloats />
        </div>
    );
}
