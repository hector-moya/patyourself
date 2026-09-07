/**
 * The gym workflow's registered recording surface — the compact entry point
 * `WorkflowRecord` draws inline on the dashboard and catch-up cards.
 *
 * The session itself is a dedicated Inertia page (`training/session`), not
 * something this slot draws inline: this component's whole job is one line
 * that gets the user there, materialising the occasion first when there
 * isn't one yet.
 *
 * With an occurrence already in hand, that is a plain link to the session
 * screen. Without one — a cue-anchored action whose slot does not exist
 * yet — recording has to begin first, which is a write
 * (`training.session.materialise`) and cannot be a plain link. Submitting it
 * redirects straight into `training.session.show`, and `onSuccess` reads the
 * occurrence id off that landed page and hands it to the host via
 * `onOccurrenceMaterialised` — see that callback's own docblock in
 * `workflows.ts` for why the host needs it at all.
 */
import { Form, Link } from '@inertiajs/react';

import type { WorkflowRecordProps } from '@/patyourself/workflows';
import { materialise, show } from '@/routes/training/session';

const LINE_CLASSNAME =
    'mt-2 inline-block text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground disabled:opacity-60 disabled:no-underline';

/** The occurrence id off a landed page's props, or null when it carries none. */
function occurrenceIdFrom(props: Record<string, unknown>): number | null {
    return typeof props.occurrence_id === 'number' ? props.occurrence_id : null;
}

export default function GymRecord({
    occurrenceId,
    actionId,
    onOccurrenceMaterialised,
}: WorkflowRecordProps) {
    if (occurrenceId !== null) {
        return (
            <Link href={show.url(occurrenceId)} className={LINE_CLASSNAME}>
                Track sets →
            </Link>
        );
    }

    return (
        <Form
            action={materialise.url(actionId)}
            method="post"
            onSuccess={(page) => {
                const id = occurrenceIdFrom(page.props);

                if (id !== null) {
                    onOccurrenceMaterialised(id);
                }
            }}
            data-testid="gym-record-begin"
        >
            {({ processing }) => (
                <button
                    type="submit"
                    disabled={processing}
                    className={LINE_CLASSNAME}
                >
                    Track sets →
                </button>
            )}
        </Form>
    );
}
