/**
 * PatYourSelf — Coach screen (chat home). The daily driver: coach bubbles,
 * one daily-log action card per loop, a skip → reason → new-strategy
 * diagnostic, and a composer dock. State is an in-memory reducer ported from
 * the prototype; the deliberate ~300ms coach-reply beat is preserved.
 *
 * Backend wiring (real CoachService + persisted logs/strategies) lands in
 * later tasks — for now `getCoachResponse` is the swappable seam.
 */
import { useEffect, useReducer, useRef, useState } from 'react';
import { Composer, Eyebrow } from './primitives';
import { CoachBubble, DayCard, ReasonChips, ReplyChips, SystemLine, UserBubble } from './chat';
import {
  activeStrategy, countDoneToday, getCoachResponse, REPLY_CHIPS, SEED_INTENTIONS, todayLog, ymd, today,
  type CoachAction, type Intention, type Outcome, type ThreadItem,
} from './data';

interface State {
  intentions: Intention[];
  thread: ThreadItem[];
}
type Action =
  | { type: 'user_msg'; text: string }
  | { type: 'coach_msg'; items: ThreadItem[] }
  | { type: 'log_outcome'; intentionId: string; outcome: Outcome }
  | { type: 'add_strategy'; payload: string };

function buildInitialThread(intentions: Intention[]): ThreadItem[] {
  const items: ThreadItem[] = [...getCoachResponse('morning_greet', { intentions })];
  for (const it of intentions) items.push({ kind: 'action-card', intentionId: it.id });
  return items;
}

function initState(): State {
  const intentions = SEED_INTENTIONS.map((i) => ({ ...i, log: i.log.slice(), strategies: i.strategies.slice() }));
  return { intentions, thread: buildInitialThread(intentions) };
}

/** Most recently discussed loop — drives which loop `add_strategy` targets. */
function findLastDiscussed(state: State): string | null {
  for (let i = state.thread.length - 1; i >= 0; i--) {
    const t = state.thread[i];
    if (t.kind === 'action-card' || t.kind === 'reason-chips') return t.intentionId;
  }
  return null;
}

function reducer(state: State, action: Action): State {
  switch (action.type) {
    case 'user_msg':
      return { ...state, thread: [...state.thread, { kind: 'user', text: action.text }] };
    case 'coach_msg':
      return { ...state, thread: [...state.thread, ...action.items] };
    case 'log_outcome': {
      const t = ymd(today);
      const intentions = state.intentions.map((it) => {
        if (it.id !== action.intentionId) return it;
        const filtered = it.log.filter((l) => l.date !== t);
        return { ...it, log: [...filtered, { date: t, outcome: action.outcome }] };
      });
      return { ...state, intentions };
    }
    case 'add_strategy': {
      const focusId = findLastDiscussed(state) || state.intentions[0].id;
      const intentions = state.intentions.map((it) => {
        if (it.id !== focusId) return it;
        const strategies = it.strategies.map((s) =>
          s.status === 'active' ? { ...s, status: 'failed' as const, reason: s.reason || 'Re-strategized' } : s);
        const nextV = (strategies[strategies.length - 1]?.version || 0) + 1;
        strategies.push({ version: nextV, action: action.payload, status: 'active', reason: 'Iteration on previous version.', createdAt: ymd(today) });
        return { ...it, strategies };
      });
      return { ...state, intentions };
    }
    default:
      return state;
  }
}

export function CoachScreen() {
  const [state, dispatch] = useReducer(reducer, undefined, initState);
  const { intentions, thread } = state;
  const [input, setInput] = useState('');
  const scrollRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const el = scrollRef.current;
    if (el) el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' });
  }, [thread.length]);

  const doneCount = countDoneToday(intentions);
  const total = intentions.length;

  function send(text: string) {
    if (!text || !text.trim()) return;
    dispatch({ type: 'user_msg', text });
    setInput('');
    setTimeout(() => dispatch({ type: 'coach_msg', items: getCoachResponse('typed_general') }), 350);
  }
  function logCard(intentionId: string, outcome: Outcome) {
    dispatch({ type: 'log_outcome', intentionId, outcome });
    const it = intentions.find((i) => i.id === intentionId);
    const intent = outcome === 'skipped' ? 'logged_skip_ask_reason' : outcome === 'urge-survived' ? 'logged_urge' : 'logged_done';
    setTimeout(() => dispatch({ type: 'coach_msg', items: getCoachResponse(intent, { intention: it }) }), 300);
  }
  function pickReason(intentionId: string, reason: string) {
    const it = intentions.find((i) => i.id === intentionId);
    dispatch({ type: 'user_msg', text: reason });
    setTimeout(() => {
      const r = reason.toLowerCase();
      const proposalText = r.includes('tired') ? 'Move action to evenings'
        : r.includes('time') ? 'Halve the action (under 2 minutes)'
          : r.includes('forgot') ? 'Pair with brushing teeth'
            : 'Try a 30-second version';
      dispatch({ type: 'coach_msg', items: getCoachResponse('reason_received_propose_strategy', { intention: it, reason, proposalText }) });
    }, 320);
  }
  function onCoachAction(action: CoachAction) {
    if (action.intent === 'accept_strategy') {
      dispatch({ type: 'add_strategy', payload: action.payload || '' });
      setTimeout(() => dispatch({ type: 'coach_msg', items: getCoachResponse('strategy_accepted') }), 300);
    } else if (action.intent === 'propose_stack') {
      dispatch({ type: 'coach_msg', items: [{ kind: 'coach', sig: 'stack', html: 'Try this: <em>after lifting, do five minutes of mobility on the mat.</em> Same cue, new tail.' }] });
    } else if (action.intent === 'dismiss') {
      dispatch({ type: 'coach_msg', items: [{ kind: 'coach', html: "Got it — I won't push." }] });
    } else if (action.intent === 'open_setup') {
      dispatch({ type: 'coach_msg', items: [{ kind: 'coach', sig: 'setup', html: "Let's build a fresh loop — the setup flow opens here soon." }] });
    }
  }

  const weekday = new Date().toLocaleDateString('en-US', { weekday: 'long' });

  return (
    <div className="screen-chat">
      <div className="coach-head">
        <div className="coach-head__title">
          <Eyebrow>Today · {weekday}</Eyebrow>
          <h1>Coach</h1>
        </div>
        <div className="coach-progress">
          <span className="coach-progress__label"><b>{doneCount}</b> of {total} logged</span>
          <span className="coach-progress__dots">
            {Array.from({ length: total }).map((_, i) => <i key={i} className={i < doneCount ? 'done' : ''} />)}
          </span>
        </div>
      </div>

      <div className="coach-body">
        <div className="screen-chat" style={{ minHeight: 0 }}>
          <div className="chat-scroll" ref={scrollRef}>
            <div className="chat-inner">
              <SystemLine>Morning session</SystemLine>
              {thread.map((item, idx) => {
                if (item.kind === 'coach')
                  return <CoachBubble key={idx} html={item.html} actions={item.actions} onAction={onCoachAction} />;
                if (item.kind === 'user')
                  return <UserBubble key={idx} text={item.text} />;
                if (item.kind === 'action-card') {
                  const it = intentions.find((i) => i.id === item.intentionId);
                  if (!it) return null;
                  const active = activeStrategy(it);
                  const t = todayLog(it);
                  return (
                    <DayCard
                      key={idx}
                      intention={it}
                      todaysAction={active ? active.action : 'No active strategy'}
                      logged={t ? t.outcome : null}
                      onLog={(o) => logCard(it.id, o)}
                    />
                  );
                }
                if (item.kind === 'reason-chips') {
                  const it = intentions.find((i) => i.id === item.intentionId);
                  if (!it) return null;
                  return <ReasonChips key={idx} onPick={(r) => pickReason(it.id, r)} />;
                }
                return null;
              })}
            </div>
          </div>
          <div className="chat-dock">
            <div className="chat-dock__inner">
              <ReplyChips chips={REPLY_CHIPS} onPick={(c) => send(c)} />
              <Composer value={input} onChange={setInput} onSend={() => send(input)} />
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
