/**
 * The bag, opened over the page it came from.
 *
 * A modal rather than a third panel, because the bag is a thing you OPEN, not a
 * thing you watch: nothing in it changes unless you act — the clearing accrues,
 * the bag does not — so a permanent panel would spend a third of the screen on
 * state that is static between your own clicks. The one ambient fact, how full
 * it is, rides the button that opens this. (F1 §8)
 *
 * Built on Radix's dialog PRIMITIVES rather than `@/components/ui/dialog`. That
 * wrapper hardcodes `bg-background`, `rounded-lg`, `p-6` and a lucide close
 * button, and the close button cannot be removed through `className` — it would
 * put a notebook X in the corner of a pixel panel. The primitives give the
 * focus trap, Esc, the overlay and the scroll lock; the skin is the panels'.
 * Same rule the CSS block already states: inside these frames the art sets the
 * rules.
 *
 * Controlled, never self-managing. Two reasons, and both are load-bearing: an
 * Inertia post re-renders the page and an uncontrolled dialog would close on
 * the way through (you would buy a skill and watch the bag vanish), and the
 * encounter that reveals a skill has to be able to open this from outside.
 *
 * THE RULE THIS SURFACE KEEPS: no total, no completion, no end state. A skill
 * Blob has not met is absent from the DOM rather than greyed; one it cannot
 * afford is listed WITH its price and only its button is disabled. A price you
 * cannot pay yet is a menu. A greyed row is a lock.
 */
import { Form } from '@inertiajs/react';
import * as Dialog from '@radix-ui/react-dialog';

import type { CompanionBagData } from '@/patyourself/companion';
import { name as renameRoute } from '@/routes/companion';
import { store as buildRoute } from '@/routes/companion/build';
import { store as learnRoute } from '@/routes/companion/skills';

export function CompanionBag({
    bag,
    open,
    said = null,
    onOpenChange,
}: {
    bag: CompanionBagData;
    open: boolean;
    /**
     * What the last press did, when this is the surface that can be seen.
     *
     * The room's own bubble is behind the overlay while the bag is up, so a
     * refusal shown only there would be a button that appears to do nothing.
     */
    said?: string | null;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <Dialog.Portal>
                {/* A flat wash, no blur: blur is notebook chrome, and the thing
                    behind this is a world. */}
                <Dialog.Overlay className="c-bagveil" />

                <Dialog.Content
                    className="pixel-frame c-panel c-bag"
                    // Radix warns without a description and links one by id.
                    // There is nothing to say here that the ribbon does not
                    // already say, and inventing a sentence to satisfy a
                    // warning would put words on screen that mean nothing.
                    aria-describedby={undefined}
                >
                    <div className="c-ribbon">
                        <Dialog.Title className="c-bagtitle">
                            The bag
                        </Dialog.Title>
                        <span>
                            {bag.held} / {bag.capacity}
                        </span>
                        <Dialog.Close
                            className="c-saidx"
                            aria-label="Close the bag"
                        >
                            ×
                        </Dialog.Close>
                    </div>

                    {said !== null && <p className="c-bagsaid">{said}</p>}

                    <Held bag={bag} />
                    <Build recipes={bag.recipes} />
                    <Skills bag={bag} />
                    <Rename name={bag.name} />
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}

/**
 * What Blob is carrying. An empty bag says so plainly and owes nothing — the
 * same register as the record's "that is the whole of it, so far".
 */
function Held({ bag }: { bag: CompanionBagData }) {
    if (bag.items.length === 0) {
        return (
            <p className="c-bagnone">{bag.name} is not carrying anything yet.</p>
        );
    }

    return (
        <>
            <p className="c-baggrp">Held</p>
            <ul className="c-bagrows">
                {bag.items.map((item) => (
                    <li key={item.item} className="c-bagrow">
                        <span>{item.label}</span>
                        <b className="c-bagqty">{item.quantity}</b>
                    </li>
                ))}
            </ul>
        </>
    );
}

/**
 * What those materials are for. A recipe is a PRICE, never a requirement: it
 * says what the thing costs, and says nothing about what you have not got.
 *
 * Absent entirely until an ingredient has been met, because naming a basket
 * before you know what reeds are is a preview of something that has not
 * happened.
 */
function Build({ recipes }: { recipes: CompanionBagData['recipes'] }) {
    if (recipes.length === 0) {
        return null;
    }

    return (
        <>
            <p className="c-baggrp">Build</p>
            <ul className="c-bagrows">
                {recipes.map((recipe) => (
                    <li key={recipe.item} className="c-bagrow">
                        <span>{recipe.label}</span>
                        <Form
                            {...buildRoute.form()}
                            options={{ preserveScroll: true }}
                            className="c-bagbuy"
                        >
                            {({ processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="item"
                                        value={recipe.item}
                                    />
                                    <button
                                        type="submit"
                                        className="pixel-button"
                                        disabled={
                                            processing || !recipe.buildable
                                        }
                                    >
                                        {[
                                            ...Object.entries(
                                                recipe.recipe,
                                            ).map(
                                                ([item, count]) =>
                                                    `${count} ${item}`,
                                            ),
                                            // Last, and named plainly. A tool
                                            // is part of the price, so it sits
                                            // in the price and gets no label,
                                            // no "requires" and no explanation
                                            // of its own.
                                            ...(recipe.tool === null
                                                ? []
                                                : [recipe.tool]),
                                        ].join(', ')}
                                    </button>
                                </>
                            )}
                        </Form>
                    </li>
                ))}
            </ul>
        </>
    );
}

/**
 * The skill list: only skills whose subject Blob has already met.
 *
 * It grows and it never shows its own length. No total, no "2 of 6", no greyed
 * rows and no prerequisites tree — you cannot be behind on it, because there is
 * no bottom to reach.
 *
 * A learned skill stays listed rather than disappearing. The list is what Blob
 * has met, and meeting it is what happened.
 */
function Skills({ bag }: { bag: CompanionBagData }) {
    if (bag.skills.length === 0) {
        return null;
    }

    return (
        <>
            <p className="c-baggrp">{bag.name} could learn</p>
            <ul className="c-bagrows">
                {bag.skills.map((skill) => (
                    <li key={skill.skill} className="c-bagrow">
                        <span>{skill.label}</span>
                        {skill.known ? (
                            <span className="c-bagknown">known</span>
                        ) : (
                            <Form
                                {...learnRoute.form()}
                                options={{ preserveScroll: true }}
                                className="c-bagbuy"
                            >
                                {({ processing }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="skill"
                                            value={skill.skill}
                                        />
                                        {/* Listed with its price whether or
                                            not it can be paid. The BUTTON is
                                            disabled, never the row: a price
                                            you cannot meet yet is a menu, and
                                            a greyed row is a lock. */}
                                        <button
                                            type="submit"
                                            className="pixel-button"
                                            disabled={
                                                processing || !skill.affordable
                                            }
                                        >
                                            {skill.price} xp
                                        </button>
                                    </>
                                )}
                            </Form>
                        )}
                    </li>
                ))}
            </ul>
        </>
    );
}

/**
 * What to call the companion.
 *
 * Here because this is the only pixel-panel surface that opens, and because the
 * modal is already the companion's own drawer — the one part of the page about
 * Blob's own things rather than about the room or the record.
 *
 * A plain field and a Save, not a title that turns into an input when clicked:
 * that gesture is one nobody discovers, and this is the single control in the
 * whole feature a person might actually go looking for.
 *
 * Clearing it puts the name back to "Blob", which the placeholder says out loud
 * so nobody has to find out by trying.
 */
function Rename({ name }: { name: string }) {
    return (
        <Form
            {...renameRoute.form()}
            options={{ preserveScroll: true, preserveState: true }}
            className="c-bagname"
        >
            {({ processing }) => (
                <>
                    <label htmlFor="companion-name">Name</label>
                    <input
                        id="companion-name"
                        name="name"
                        type="text"
                        maxLength={24}
                        defaultValue={name === 'Blob' ? '' : name}
                        placeholder="Blob"
                        className="c-bagfield"
                    />
                    <button
                        type="submit"
                        className="pixel-button"
                        disabled={processing}
                    >
                        Save
                    </button>
                </>
            )}
        </Form>
    );
}
