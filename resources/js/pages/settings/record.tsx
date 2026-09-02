import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { show as exportRecord } from '@/routes/export';
import { edit as editRecord } from '@/routes/record';

/**
 * The record, in the user's hands.
 *
 * Plain anchors rather than Inertia `<Link>`s on purpose: `/export` streams a
 * file download, and Inertia would try to read that response as a page and
 * find no page in it. `download` is deliberately absent too — the endpoint
 * already sends `Content-Disposition: attachment` with the filename it wants,
 * and repeating it here is a second source of truth for the same thing.
 *
 * Two formats, because they answer different questions: JSON is everything, for
 * anywhere else it might go; Markdown is the notebook to read. No importer is
 * offered and none is hinted at — see ExportController for why there is not one.
 */
export default function Record() {
    return (
        <>
            <Head title="Your record" />

            <h1 className="sr-only">Your record</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Your record"
                    description="Everything the app holds about your loops, in full"
                />

                <p className="text-sm text-muted-foreground">
                    Every loop, every strategy version, every outcome and the
                    reasons you gave. It downloads as one file and nothing
                    leaves the app until you ask for it.
                </p>

                <div className="flex flex-wrap gap-3">
                    <a
                        href={exportRecord.url()}
                        className="inline-flex items-center rounded-md border border-border px-4 py-2 text-sm text-foreground transition-colors hover:bg-muted"
                    >
                        Download as JSON
                    </a>
                    <a
                        href={exportRecord.url({ query: { format: 'md' } })}
                        className="inline-flex items-center rounded-md border border-border px-4 py-2 text-sm text-foreground transition-colors hover:bg-muted"
                    >
                        Download as Markdown
                    </a>
                </div>

                <p className="text-sm text-muted-foreground">
                    JSON keeps the structure, for reading somewhere else.
                    Markdown reads as the notebook.
                </p>
            </div>
        </>
    );
}

Record.layout = {
    breadcrumbs: [
        {
            title: 'Your record',
            href: editRecord(),
        },
    ],
};
