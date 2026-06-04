import { Head, Link } from '@inertiajs/react';
import { Eyebrow } from '@/patyourself/primitives';

/**
 * PatYourSelf — public landing page. Branded with the warm DS; routes to
 * Fortify login/register. The app itself lives behind auth at /dashboard.
 */
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
          <Link href="/login" className="py-btn py-btn--ghost py-btn--sm">Log in</Link>
          <Link href="/register" className="py-btn py-btn--secondary py-btn--sm">Create account</Link>
        </nav>
      </header>

      <main className="py-landing__hero">
        <Eyebrow>A coach, not a tracker</Eyebrow>
        <h1 className="ds-display">Change the loop,<br />not just the streak.</h1>
        <p className="ds-lead py-landing__lead">
          Every habit is a loop — cue, craving, response, reward. patyourself coaches you
          through one small change at a time, and reworks the plan when life gets in the way.
        </p>

        <div className="py-landing__cta">
          <Link href="/register" className="py-btn py-btn--primary py-btn--md">Get started</Link>
          <Link href="/login" className="py-btn py-btn--secondary py-btn--md">I already have an account</Link>
        </div>

        <div className="py-landing__loops">
          <span className="py-chip py-chip--cue">Cue</span>
          <span className="py-chip py-chip--craving">Craving</span>
          <span className="py-chip py-chip--response">Response</span>
          <span className="py-chip py-chip--reward">Reward</span>
        </div>
      </main>
    </div>
  );
}
