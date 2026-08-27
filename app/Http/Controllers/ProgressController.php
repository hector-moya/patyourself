<?php

namespace App\Http\Controllers;

use App\Models\Intention;
use App\Services\Progress\LoopProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The read-only progress dashboard: the user's active loops as metric cards,
 * each linking into that loop's lab record.
 *
 * The per-loop drill-in that used to live here folded into `/loops/{loop}`,
 * which now carries the current experiment, the per-version evidence and the
 * reflection on one screen. `progress/{intention}` survives as a redirect.
 */
class ProgressController extends Controller
{
    public function index(Request $request, LoopProgress $progress): Response
    {
        $loops = $request->user()->intentions()
            ->active()
            ->with(['activeStrategy', 'latestSummary', 'actionLogs'])
            ->latest()
            ->get()
            ->map(fn (Intention $loop): array => [
                'id' => $loop->id,
                'title' => $loop->title,
                'type' => $loop->type,
                ...$progress->forLoop($loop),
                'summary_excerpt' => $this->excerpt($loop->latestSummary?->content),
            ])
            ->values();

        return Inertia::render('progress/index', [
            'loops' => $loops,
        ]);
    }

    /** First line of the rolling summary, trimmed for the index card. */
    private function excerpt(?string $content): ?string
    {
        if ($content === null || trim($content) === '') {
            return null;
        }

        return Str::limit(trim(strtok($content, "\n")), 120);
    }
}
