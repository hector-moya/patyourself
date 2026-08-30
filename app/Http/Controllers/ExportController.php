<?php

namespace App\Http\Controllers;

use App\Services\Export\JsonRecordFormatter;
use App\Services\Export\MarkdownRecordFormatter;
use App\Services\Export\RecordExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands the user their own record, in full.
 *
 * Two formats from one payload: JSON is the complete machine-readable dump,
 * Markdown is the notebook as prose. Read-only — there is deliberately no
 * importer, because a round trip means identity collisions, versioning and
 * partial-failure semantics, and nothing needs it.
 *
 * An unknown format answers JSON rather than erroring: someone hand-editing a
 * URL should get their record, not a 422.
 */
class ExportController extends Controller
{
    public function __construct(
        private readonly RecordExport $export,
        private readonly JsonRecordFormatter $json,
        private readonly MarkdownRecordFormatter $markdown,
    ) {}

    public function show(Request $request): StreamedResponse
    {
        $markdown = $request->query('format') === 'md';
        $record = $this->export->forUser($request->user());

        $body = $markdown
            ? $this->markdown->render($record)
            : $this->json->render($record);

        $filename = 'patyourself-'.now()->format('Y-m-d').($markdown ? '.md' : '.json');

        return response()->streamDownload(
            function () use ($body): void {
                echo $body;
            },
            $filename,
            ['Content-Type' => $markdown ? 'text/markdown; charset=utf-8' : 'application/json'],
        );
    }
}
