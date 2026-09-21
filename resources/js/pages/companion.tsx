import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

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
import {
    CompanionRoom,
    roomOffset,
    roomSize,
} from '@/patyourself/companion-room';
import { partOfDay } from '@/patyourself/part-of-day';
import {
    nodeCell,
    nodeSprite,
    sceneFor,
    SHELTER_CELL,
    shelterSprite,
} from '@/patyourself/scenes';
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

    // Where Blob is looking, and nothing more. NOT STORED and never posted:
    // inside/outside is a view, not a thing you own, and it resets on load the
    // way the bag does. What Blob has BUILT is the stored half, and it lives
    // on the bag payload.
    const [inside, setInside] = useState(false);

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
                    inside={inside}
                    onReact={react}
                    onOpenBag={() => setBagOpen(true)}
                    onTouchNode={touchNode}
                    onToggleInside={() => setInside((was) => !was)}
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
    inside,
    onReact,
    onOpenBag,
    onTouchNode,
    onToggleInside,
}: {
    companion: CompanionData;
    animation: AnimationName;
    frame: number;
    hour: number;
    now: Date;
    remark: string | null;
    said: string | null;
    bag: CompanionBagData;
    inside: boolean;
    onReact: (name: AnimationName) => void;
    onOpenBag: () => void;
    onTouchNode: (node: string) => void;
    onToggleInside: () => void;
}) {
    const [showRemark, setShowRemark] = useState(true);
    const part = partOfDay(hour, companion.room);
    const shelterAt = sceneFor(companion.scene).shelter;

    const stage = useRef<HTMLDivElement>(null);
    /**
     * Set by a hotspot that is about to remove itself — going inside unmounts
     * `ShelterSpot`, and draining the heap deletes its node row and with it
     * `NodeSpot`. A ref rather than state: this must not cause a render of
     * its own, it only has to survive until the next commit.
     */
    const recoverFocus = useRef(false);

    /**
     * No dependency array: this has to run after EVERY commit, because what it
     * is watching for is an element disappearing rather than a value changing.
     * The ref is the gate, so it costs a boolean check on renders that are not
     * about focus.
     *
     * The condition is the whole safety of it. Focus is recovered only when it
     * was actually LOST — a click that left focus somewhere real must not have
     * it dragged back to the stage, which would be worse than the bug.
     */
    useEffect(() => {
        if (!recoverFocus.current) {
            return;
        }

        recoverFocus.current = false;

        if (document.activeElement === document.body) {
            stage.current?.focus();
        }
    });

    return (
        <section className="pixel-frame c-panel">
            {/* Where Blob is, and when — the one thing this screen never
                said. Both are already true of the drawing; naming them is
                what makes the light read as deliberate rather than broken. */}
            <div className="c-place">
                <b>
                    <CompanionGlyph kind="body" size={14} />
                    {/* Where Blob actually is. The record decides the world;
                        a press decides which side of the wall Blob is on, and
                        the bar says the one that is true right now. */}
                    the{' '}
                    {inside && bag.shelter.label !== null
                        ? bag.shelter.label
                        : sceneFor(companion.scene).name}
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

            <div className="c-stage" ref={stage} tabIndex={-1}>
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
                    inside={inside}
                    shelter={bag.shelter.built}
                    nodes={bag.nodes}
                />

                {/* The clearing's own things, laid over the picture as real
                    buttons rather than drawn inside the svg. The scene is a
                    role="img" and everything reachable in it has to be
                    reachable from a keyboard too — the same rule Poke already
                    follows.

                    ALL THREE ARE HERE FROM THE START, whatever the record says.
                    Clicking one you cannot use does not fail and shows no
                    lock: Blob turns it over and puts it down again, and that
                    encounter is what puts the skill in the list.

                    Not reachable from indoors: nothing grows in a room. */}
                {!inside &&
                    sceneFor(companion.scene).nodes.map((spec) => {
                        const node = bag.nodes.find(
                            (candidate) => candidate.node === spec.node,
                        );
                        const cell = nodeCell(spec.node);

                        // `available: 0` with an empty band is a FIXTURE-only
                        // shape, kept so tests can still find the heap's row —
                        // the server cannot actually send it.
                        // `CompanionBag::nodes()` skips a skill-less node with
                        // nothing standing at it rather than sending one at
                        // zero, and were a heap ever sent at `available: 0`
                        // its band would be `bandFor(0)`, which resolves to
                        // `'bare'`, not `''`. Gated here on the sprite
                        // resolving so this control agrees with `NodeLayer`,
                        // which already draws nothing for a band with no art:
                        // without this, an empty heap (or any future node
                        // whose band has no art) leaves an invisible,
                        // unlabelled hit region standing in the clearing —
                        // the defect 15e893a fixed for the shelter.
                        if (
                            node === undefined ||
                            cell === undefined ||
                            nodeSprite(spec.node, node.band) === undefined
                        ) {
                            return null;
                        }

                        return (
                            <NodeSpot
                                key={spec.node}
                                label={node.label}
                                available={node.available}
                                cell={cell}
                                // The art's centre, not the base centre `at`
                                // names: `.c-node` is translated by
                                // -50%,-50%, and the art sits above the point
                                // the thing stands on rather than around it.
                                at={roomOffset(
                                    spec.at[0],
                                    spec.at[1] - cell[1] / 2,
                                )}
                                onClick={() => {
                                    // A drained heap is deleted, so this
                                    // control can remove itself — the same
                                    // shape `ShelterSpot` has.
                                    recoverFocus.current = true;
                                    onTouchNode(spec.node);
                                }}
                            />
                        );
                    })}

                {/* What Blob built, standing where it was built. One at a
                    time and never two: the stages replace one another, so
                    there is only ever one thing here.

                    A real button over the picture rather than a shape inside
                    the svg, the same rule the node hotspots follow. */}
                {!inside &&
                    bag.shelter.built !== null &&
                    shelterAt !== undefined &&
                    // `CompanionBag::shelter()` deliberately keeps forwarding
                    // a stage config no longer defines — nothing about Blob
                    // is ever taken because an author edited a list. Gated
                    // here on the sprite resolving so this control agrees
                    // with `ShelterLayer`, which already draws nothing for
                    // that stage: without this, a retired stage leaves an
                    // invisible, unlabelled hit region standing in the
                    // clearing.
                    shelterSprite(bag.shelter.built) !== undefined && (
                        <ShelterSpot
                            label={bag.shelter.label ?? bag.shelter.built}
                            // The art's centre, not the base centre `shelterAt`
                            // names: `.c-node` is translated by -50%,-50%, and
                            // the art sits above the point the structure
                            // stands on rather than around it.
                            at={roomOffset(
                                shelterAt[0],
                                shelterAt[1] - SHELTER_CELL / 2,
                            )}
                            onClick={() => {
                                recoverFocus.current = true;
                                onToggleInside();
                            }}
                        />
                    )}
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

                {/* Only once there is something to go into. A door to a room
                    that has not been built would be a preview, and this
                    feature shows what has happened.

                    A real button rather than a shape in the picture, the same
                    rule the node hotspots follow: everything reachable in this
                    frame has to be reachable from a keyboard. */}
                {bag.shelter.built !== null && (
                    <button
                        type="button"
                        className="pixel-button"
                        onClick={onToggleInside}
                    >
                        {inside ? 'Go outside' : 'Go inside'}
                    </button>
                )}

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
 * One thing standing in the clearing, as a control over its own picture.
 *
 * A real `<button>` laid over the svg rather than a shape inside it, so it is
 * focusable, announced, and reachable without a mouse — the same rule
 * `ShelterSpot` follows and the reason neither is a `<foreignObject>`.
 *
 * IT CARRIES NO TEXT. The art says what is standing there and how much of it,
 * so printing the name again would be this button narrating the picture. That
 * also retires the label-sizing problem BLOB.md §12 has carried since F1: the
 * labels were a fixed 8px font that did not scale with the svg, so below
 * roughly a 300px stage they wrapped to a second line. The box is measured in
 * room units now and scales with the picture.
 *
 * NO `is-known` EITHER, and that RESTORES a rule rather than dropping one: a
 * node you cannot use is supposed to look exactly like one you can, because
 * that sameness IS the mechanic, and the border this used to grow when a
 * skill was bought quietly contradicted it. Putting the distinction on the
 * art instead would be worse — a fact about the record drawn into the world.
 *
 * `aria-label` carries the one thing the picture cannot: the exact amount. It
 * overrides the subtree entirely, so the number has to be folded in by hand —
 * otherwise deleting the text takes it away from a screen reader while
 * leaving it in the picture for everyone else.
 */
function NodeSpot({
    label,
    available,
    cell,
    at,
    onClick,
}: {
    label: string;
    available: number;
    cell: readonly [number, number];
    at: { left: string; top: string };
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            className={cn('c-node', 'c-node--art')}
            style={{ ...at, ...roomSize(cell[0], cell[1]) }}
            // Only once there is something to take. A zero would be a count
            // of what you have not got.
            aria-label={available > 0 ? `${label}, ${available}` : label}
            onClick={onClick}
        />
    );
}

/**
 * The doorway into what Blob built, laid over its own art in the clearing.
 *
 * Sized to that art rather than to a word, and carrying no text of its own —
 * the art already says what is standing there, so printing its name again
 * (a stage number, "1 of 3", the label itself) would be this button
 * narrating the picture instead of sitting quietly over it. `aria-label`
 * carries the one thing the picture cannot: what pressing it does.
 *
 * A real `<button>` over the picture rather than a shape inside the svg, the
 * same rule `NodeSpot` follows and does not change here.
 *
 * Clicking it goes inside, which is the same thing the plinth's own control
 * does. Two ways in rather than one, for the same reason Poke is both the
 * scene's own tap and a button: the obvious gesture should work, and it must
 * not be the only one that does.
 */
function ShelterSpot({
    label,
    at,
    onClick,
}: {
    label: string;
    at: { left: string; top: string };
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            className={cn('c-node', 'c-shelter', 'c-node--art')}
            style={{ ...at, ...roomSize(SHELTER_CELL, SHELTER_CELL) }}
            // The picture says what it is; the name says what pressing does.
            aria-label={`Go inside the ${label}`}
            onClick={onClick}
        />
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
