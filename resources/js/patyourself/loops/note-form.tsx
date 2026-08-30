import { Form } from '@inertiajs/react';
import { Button } from '@/patyourself/primitives';
import notes from '@/routes/loops/notes';

const FIELD_CLASS =
    'w-full rounded-md border border-border bg-background px-3 py-2 text-sm';

/**
 * An observation that is not an outcome. Stored verbatim; there is no edit and
 * no delete, because the record is append-only.
 */
export function NoteForm({ loopId }: { loopId: number }) {
    return (
        <Form
            {...notes.store.form(loopId)}
            resetOnSuccess
            className="space-y-2"
        >
            {({ processing, errors }) => (
                <>
                    <label htmlFor="note-body" className="sr-only">
                        Add a note
                    </label>
                    <textarea
                        id="note-body"
                        name="body"
                        rows={2}
                        placeholder="Something you noticed"
                        className={FIELD_CLASS}
                    />
                    {errors.body && (
                        <p className="text-sm text-destructive">
                            {errors.body}
                        </p>
                    )}
                    <Button type="submit" disabled={processing}>
                        Add note
                    </Button>
                </>
            )}
        </Form>
    );
}
