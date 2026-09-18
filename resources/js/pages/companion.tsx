import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { useSpriteClock } from '@/hooks/use-sprite-clock';
import CoachLayout from '@/layouts/coach-layout';
import { cn } from '@/lib/utils';
import { BottomNav } from '@/patyourself/bottom-nav';
import {
    actionsFor,
    ambientFor,
    selfStartedFor,
} from '@/patyourself/companion';
import type {
    CompanionBagData,
    CompanionData,
    CompanionUnlockData,
} from '@/patyourself/companion';
import type { AnimationName } from '@/patyourself/companion-animations';
import { CompanionBag } from '@/patyourself/companion-bag';
import { CompanionGlyph } from '@/patyourself/companion-glyph';
import { CompanionRoom, roomOffset } from '@/patyourself/companion-room';
import { partOfDay } from '@/patyourself/part-of-day';
import { sceneFor } from '@/patyourself/scenes';
import { store as touchNodeRoute } from '@/routes/companion/nodes';

interface CompanionPageProps {
    companion: CompanionData;
    /**
     * One thing Blob has to say this visit, or null. Written by the coach and
     * relayed verbatim — the app does not compose it and does not edit it.
     *
     * Only ever on this screen. A line beside every logged breakfast is
     * wallpaper within a week, and wallpaper is worse than silence.
     */
    remark?: string | null;
    /**
     * The chosen half: what has been spent, bought, gathered and built.
     *
     * Behind a button rather than on the page, because the bag is a thing you
     * open and not a thing you watch — see `CompanionBag`.
     */
    bag: CompanionBagData;
    /**
     * What just happened in the clearing, or null. The app's own voice — Blob
     * turning something over, or a bag with no room left in it.
     *
     * Distinct from `remark`, which is the coach's. Both are Blob talking and
     * both land in the same bubble; this one wins when they collide, because
     * it is about the click that was just made.
     */
    said?: string | null;
    /**
     * Whether that encounter revealed a skill, which is the one thing that
     * opens the bag without being asked.
     *
     * The payment for the bag being a modal: a panel would have made "click a
     * node, its skill appears in the list" visible, and this shows it instead —
     * once, as the response to a click you made. Never on load, never on a
     * visit, and never sticky.
     */
    revealed?: boolean;
}

/**
 * Blob's screen: the room, the things you can do to Blob, and a plain list of
 * what it has and when each part arrived.
 *
 * The list is history. There are no locked slots, no count of what is left and
 * no preview of what comes next — the moment the screen shows what has *not*
 * happened it becomes a checklist, and a checklist is a thing to be behind on.
 * Only ever show what has happened.
 *
 * Two panels in the same wood, and that is the whole layout. The pixel surface
 * holds the record as well as the room now, which is what stops the sprite art
 * and the notebook type talking past each other: inside these two frames the
 * art sets the rules, and everything outside them is the notebook it always
 * was. The record is not a footnote to the room — it is the other half of the
 * screen, and on a desktop it stands beside it rather than under it.
 *
 * The clock lives here rather than inside the drawing because the buttons need
 * to reach it. Everything on this screen reads the same two numbers, and the
 * same hour: the room's light, what Blob is doing at rest, and the line naming
 * the part of day are one reading of one clock, never three.
 */
export default function CompanionPage({
    companion,
    remark = null,
    bag,
    said = null,
    revealed = false,
}: CompanionPageProps) {
    const now = useMinute();
    const hour = now.getHours();

    // Held here rather than inside CompanionBag: an Inertia post re-renders
    // this page, and a dialog managing its own state would close on the way
    // through — you would rename the companion and watch the bag vanish.
    const [bagOpen, setBagOpen] = useState(revealed);

    // `revealed` is a flash: true on exactly the render after the encounter,
    // gone by the next request. Inertia re-renders this component across visits
    // rather than remounting it, so the initialiser above only fires once and
    // the prop has to be watched as well.
    //
    // Adjusted during render against the previous value rather than in an
    // effect. An effect would open the bag a frame late and — worse — would
    // re-open it every time anything else re-rendered while the flash was still
    // on the props, which is precisely the nag this must not become. Comparing
    // to the last value means it reacts to the CHANGE, so closing it stays
    // closed.
    const [wasRevealed, setWasRevealed] = useState(revealed);

    if (revealed !== wasRevealed) {
        setWasRevealed(revealed);

        if (revealed) {
            setBagOpen(true);
        }
    }

    /**
     * Touching something in the clearing. One gesture from here — the server
     * decides whether Blob looks at it or gathers from it, because that
     * difference is about what Blob knows and not about what was clicked.
     *
     * `preserveScroll` so the page does not jump out from under the press.
     * State is deliberately NOT preserved: the bag, the balance and what is
     * standing all change, and the whole point of the click is to see that.
     */
    const touchNode = (node: string) => {
        router.post(touchNodeRoute(node).url, {}, { preserveScroll: true });
    };

    const { animation, frame, react } = useSpriteClock(
        ambientFor(companion, hour),
        selfStartedFor(companion, hour),
    );
    const nothingYet = companion.unlocks.length === 0;

    // Whether the remark shows is `remark !== null` alone — the server's call,
    // via CompanionController, on whether Blob exists. `nothingYet` decides
    // the room and the buttons here, same as always, but must never also gate
    // the remark: `nothingYet` and the controller's `stageIndex() === 0` are
    // two independent expressions of the same fact, identical today only
    // because nothing enforces that they agree. If they ever diverged, a
    // remark reachable only through the `!nothingYet` branch would have
    // already been burned into the session by the controller and then never
    // drawn — the exact failure `test_before_blob_exists_no_remark_is_drawn`
    // exists to prevent, just reached from the other side. So this branch
    // relays it too, as plain type: there is no scene here to put a bubble on.
    if (nothingYet) {
        return (
            <CoachLayout title="Blob" bottomNav={<BottomNav />}>
                <div className="flex flex-col items-center gap-4">
                    {/* Stated as a fact about the record, with nothing to act
                        on and nothing owed. Not an empty slot, and no empty
                        room either — there is nobody to put in it yet. */}
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        Blob turns up once there is something in the record. Log
                        an outcome — any outcome — and it arrives.
                    </p>

                    {remark !== null && (
                        <p
                            data-testid="companion-remark"
                            className="max-w-md text-center text-sm text-balance text-muted-foreground"
                        >
                            {remark}
                        </p>
                    )}
                </div>
            </CoachLayout>
        );
    }

    return (
        <CoachLayout title="Blob" bottomNav={<BottomNav />} wide>
            <div className="c-wrap">
                <RoomCard
                    companion={companion}
                    animation={animation}
                    frame={frame}
                    hour={hour}
                    now={now}
                    remark={remark}
                    bag={bag}
                    said={bagOpen ? null : said}
                    onReact={react}
                    onOpenBag={() => setBagOpen(true)}
                    onTouchNode={touchNode}
                />
                <Record companion={companion} />
            </div>

            <CompanionBag
                bag={bag}
                open={bagOpen}
                // The bubble sits behind the overlay while this is up, so a
                // line shown only there would be a click that appears to do
                // nothing. Whichever surface is visible says it.
                said={bagOpen ? said : null}
                onOpenChange={setBagOpen}
            />
        </CoachLayout>
    );
}

/**
 * The room, its plinth of buttons and Blob's own line, in one frame.
 *
 * The buttons sit on the card that holds the world rather than floating under
 * it, because what they act on is inside the frame and nothing else. Same
 * reason the remark is a bubble over the scene: it is Blob talking, and a
 * caption set below the picture reads as the app narrating Blob instead.
 */
function RoomCard({
    companion,
    animation,
    frame,
    hour,
    now,
    remark,
    said,
    bag,
    onReact,
    onOpenBag,
    onTouchNode,
}: {
    companion: CompanionData;
    animation: AnimationName;
    frame: number;
    hour: number;
    now: Date;
    remark: string | null;
    said: string | null;
    bag: CompanionBagData;
    onReact: (name: AnimationName) => void;
    onOpenBag: () => void;
    onTouchNode: (node: string) => void;
}) {
    const [showRemark, setShowRemark] = useState(true);
    const part = partOfDay(hour, companion.room);

    return (
        <section className="pixel-frame c-panel">
            {/* Where Blob is, and when — the one thing this screen never
                said. Both are already true of the drawing; naming them is
                what makes the light read as deliberate rather than broken. */}
            <div className="c-place">
                <b>
                    <CompanionGlyph kind="body" size={14} />
                    the {sceneFor(companion.scene).name}
                </b>
                {/* The balance, and nothing else. No target, no bar, no
                    "next at" — a number with a ceiling is the checklist
                    coming back in through the window.

                    Its own element rather than folded into either
                    neighbouring string: both of those are asserted by exact
                    text, and joining them would break two tests that are not
                    about XP. */}
                <span className="c-xp">{bag.xp} xp</span>

                <time dateTime={now.toISOString()}>
                    {part} ·{' '}
                    {now.toLocaleTimeString('en-GB', {
                        hour: '2-digit',
                        minute: '2-digit',
                    })}
                </time>
            </div>

            <div className="c-stage">
{/* What just happened wins over what Blob had to say: one is
                    about the click that was made a moment ago, the other is a
                    line the coach wrote some time ago. Both are Blob talking,
                    and there is one bubble. */}
                {said !== null && <p className="c-said c-did">{said}</p>}

                {said === null && remark !== null && showRemark && (
                    <p data-testid="companion-remark" className="c-said">
                        {remark}
                        <button
                            type="button"
                            className="c-saidx"
                            onClick={() => setShowRemark(false)}
                            aria-label="Dismiss what Blob said"
                        >
                            ×
                        </button>
                    </p>
                )}

                <CompanionRoom
                    companion={companion}
                    animation={animation}
                    frame={frame}
                    hour={hour}
                    className="c-scene"
                    onPoke={() => onReact('notice')}
                />

                {/* The clearing's own things, laid over the picture as real
                    buttons rather than drawn inside the svg. The scene is a
                    role="img" and everything reachable in it has to be
                    reachable from a keyboard too — the same rule Poke already
                    follows.

                    ALL THREE ARE HERE FROM THE START, whatever the record says.
                    Clicking one you cannot use does not fail and shows no
                    lock: Blob turns it over and puts it down again, and that
                    encounter is what puts the skill in the list. */}
                {sceneFor(companion.scene).nodes.map((spec) => {
                    const node = bag.nodes.find(
                        (candidate) => candidate.node === spec.node,
                    );

                    if (node === undefined) {
                        return null;
                    }

                    return (
                        <NodeSpot
                            key={spec.node}
                            label={node.label}
                            available={node.available}
                            known={node.known}
                            at={roomOffset(spec.at[0], spec.at[1])}
                            onClick={() => onTouchNode(spec.node)}
                        />
                    );
                })}
            </div>

            {/* Never disabled, never on a timer, never counted: pressing one
                is not progress, none of them touch anything the resolver
                reads, and Blob never asks to be pressed.

                Which ones are here is a different question from whether they
                work, and it is `actionsFor`'s — the two done to Blob are
                always present, and one that asks Blob to use an ability
                appears only once the ladder has announced it, absent until
                then rather than greyed.

                Poke is the odd one out and deliberately last: it is what the
                scene itself does when tapped, kept as a button so the same
                thing is reachable from a keyboard. */}
            <div className="c-plinth">
                {actionsFor(companion).map((action) => (
                    <button
                        key={action.animation}
                        type="button"
                        className="pixel-button"
                        onClick={() => onReact(action.animation)}
                    >
                        {action.label}
                    </button>
                ))}
                <button
                    type="button"
                    className="pixel-button"
                    onClick={() => onReact('notice')}
                >
                    Poke
                </button>

                {/* The odd one out in this row: every other button fires an
                    animation, this one opens a dialog. Poke is already the
                    documented odd one out, and the shared rule holds — what
                    these buttons act on is inside this frame, and everything
                    in the bag came out of it.

                    It carries the capacity because that is the bag's one
                    AMBIENT fact: the number that is true whether or not you
                    are looking. Everything else in there is static until you
                    act on it, which is the whole argument for it being a
                    modal rather than a panel. */}
                <button
                    type="button"
                    className="pixel-button c-bagbtn"
                    onClick={onOpenBag}
                >
                    Bag{' '}
                    <i>
                        {bag.held} / {bag.capacity}
                    </i>
                </button>
            </div>
        </section>
    );
}

/** The record, in the same wood as the room it belongs to. */
function Record({ companion }: { companion: CompanionData }) {
    // Newest first: the most recent thing is the thing being read, and the
    // beginning stays at the end where it belongs.
    const entries = [...companion.unlocks].reverse();
    const began = formatDay(entries[entries.length - 1].unlocked_at);

    return (
        <section className="pixel-frame c-panel c-record">
            <div className="c-ribbon">
                What has happened
                {began !== '' && <span>since {began}</span>}
            </div>

            <ul className="c-log">
                {entries.map((unlock, index) => (
                    <UnlockRow
                        key={`${unlock.kind}-${unlock.name}-${index}`}
                        unlock={unlock}
                        newest={index === 0}
                    />
                ))}
            </ul>

            {/* A statement about the record rather than a preview of one:
                everything Blob has is on this list, and there is no line here
                for what is not. */}
            <p className="c-first">that is the whole of it, so far</p>
        </section>
    );
}

/**
 * One thing standing in the clearing.
 *
 * A real `<button>` over the picture rather than a shape inside the svg, so it
 * is focusable, announced, and reachable without a mouse.
 *
 * What it shows is what is THERE: its name always, and a count only once
 * something has actually accrued. No lock, no "requires", no price — the price
 * lives in the bag, which the encounter opens. A node you cannot use looks
 * exactly like one you can, because that is the whole mechanic.
 */
function NodeSpot({
    label,
    available,
    known,
    at,
    onClick,
}: {
    label: string;
    available: number;
    known: boolean;
    at: { left: string; top: string };
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            className={cn('c-node', known && 'is-known')}
            style={at}
            onClick={onClick}
        >
            {label}
            {/* Only once there is something to take. A zero would be a count
                of what you have not got. */}
            {available > 0 && <i>{available}</i>}
        </button>
    );
}

function UnlockRow({
    unlock,
    newest,
}: {
    unlock: CompanionUnlockData;
    newest: boolean;
}) {
    // `cn` rather than a template literal: prettier's tailwind plugin
    // normalises the string inside one and eats the leading space, which
    // silently ships `class="c-entryis-new"` — the marker welded onto the
    // layout class, so the row loses its grid with the suite still green.
    return (
        <li className={cn('c-entry', newest && 'is-new')}>
            <i>
                <CompanionGlyph kind={unlock.kind} />
            </i>
            <div>
                <div className="c-etop">
                    <span className="c-ename">
                        {label(unlock)}
                        {newest && <em className="c-newtag">newest</em>}
                    </span>
                    <span className="c-edate">
                        {formatDay(unlock.unlocked_at)}
                    </span>
                </div>
                <p className="c-ebody">{unlock.message}</p>
            </div>
        </li>
    );
}

/**
 * The wall clock behind the place bar, re-read every half minute.
 *
 * Its own interval rather than the sprite clock's: that one stops when the tab
 * is hidden and holds frame 0 under reduced motion, and a time of day that
 * quietly froze at whatever it was when you last looked is worse than no time
 * at all. The same value feeds the light and the ambient, so all three still
 * turn over together.
 */
function useMinute(): Date {
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const id = window.setInterval(() => setNow(new Date()), 30_000);

        return () => window.clearInterval(id);
    }, []);

    return now;
}

/** "scarf", or "scarf (coral)" once a type has been recoloured. */
function label(unlock: CompanionUnlockData): string {
    return unlock.variant === null
        ? unlock.name
        : `${unlock.name} (${unlock.variant})`;
}

function formatDay(iso: string | null): string {
    return iso === null
        ? ''
        : new Date(iso).toLocaleDateString('en-GB', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          });
}
