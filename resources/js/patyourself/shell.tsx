/**
 * PatYourSelf — app shell. Fills the viewport with the persistent left
 * sidenav (Coach / Loops) and the active screen. Light/dark theme is driven
 * by `data-theme` on the shell wrapper.
 *
 * Scope today: Coach screen is live; Loops is a placeholder until its task.
 */
import { useEffect, useState } from 'react';
import { CoachScreen } from './coach-screen';
import { SEED_INTENTIONS } from './data';
import { Avatar, Icon } from './primitives';

type Screen = 'coach' | 'loops';
type Theme = 'light' | 'dark';

const THEME_KEY = 'patyourself:theme';

/** Last chosen theme, falling back to the OS preference, then light. */
function initialTheme(): Theme {
    if (typeof window === 'undefined') {
        return 'light';
    }

    const saved = window.localStorage.getItem(THEME_KEY);

    if (saved === 'light' || saved === 'dark') {
        return saved;
    }

    return window.matchMedia?.('(prefers-color-scheme: dark)').matches
        ? 'dark'
        : 'light';
}

function SideNav({
    screen,
    setScreen,
    loopCount,
    theme,
    toggleTheme,
}: {
    screen: Screen;
    setScreen: (s: Screen) => void;
    loopCount: number;
    theme: Theme;
    toggleTheme: () => void;
}) {
    const item = (id: Screen, name: string, label: string, count?: number) => (
        <button
            type="button"
            className={`py-navitem${screen === id ? 'is-active' : ''}`}
            onClick={() => setScreen(id)}
            aria-current={screen === id ? 'page' : undefined}
        >
            <Icon name={name} size={20} />
            <span className="py-navlabel">{label}</span>
            {count != null && (
                <span className="py-navitem__count">{count}</span>
            )}
        </button>
    );

    return (
        <nav className="py-sidenav">
            <div className="py-sidenav__brand">
                <img
                    className="brand-icon"
                    src="/patyourself/app-icon.svg"
                    alt=""
                />
                <span className="brand-word">patyourself</span>
            </div>
            {item('coach', 'message-circle', 'Coach')}
            {item('loops', 'git-branch', 'Loops', loopCount)}
            <div className="py-sidenav__foot">
                <button
                    type="button"
                    className="py-navitem"
                    onClick={toggleTheme}
                    aria-label={
                        theme === 'dark'
                            ? 'Switch to daylight theme'
                            : 'Switch to evening theme'
                    }
                >
                    <Icon name={theme === 'dark' ? 'sun' : 'moon'} size={20} />
                    <span className="py-navlabel">
                        {theme === 'dark' ? 'Daylight' : 'Evening'}
                    </span>
                </button>
                <div className="py-sidenav__profile">
                    <Avatar kind="user" initial="M" size={32} />
                    <span className="who">
                        <b>You</b>
                        <span>evening plan</span>
                    </span>
                </div>
            </div>
        </nav>
    );
}

function LoopsPlaceholder() {
    return (
        <div
            className="screen-chat"
            style={{ alignItems: 'center', justifyContent: 'center' }}
        >
            <div className="py-empty">
                <span className="py-empty__icon">
                    <Icon name="git-branch" size={26} />
                </span>
                <h2 className="ds-h2">Your loops</h2>
                <p className="ds-body py-empty__body">
                    The loops list, habit anatomy, and strategy timeline land in
                    their own task. The Coach has them for now.
                </p>
            </div>
        </div>
    );
}

export function PatYourSelfApp() {
    const [screen, setScreen] = useState<Screen>('coach');
    const [theme, setTheme] = useState<Theme>(initialTheme);

    useEffect(() => {
        window.localStorage.setItem(THEME_KEY, theme);
    }, [theme]);

    return (
        <div className="py-shell" data-theme={theme}>
            <div className="py-app">
                <SideNav
                    screen={screen}
                    setScreen={setScreen}
                    loopCount={SEED_INTENTIONS.length}
                    theme={theme}
                    toggleTheme={() =>
                        setTheme((t) => (t === 'dark' ? 'light' : 'dark'))
                    }
                />
                <div className="py-app__main">
                    <div className="py-app__content">
                        {screen === 'coach' ? (
                            <CoachScreen />
                        ) : (
                            <LoopsPlaceholder />
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
