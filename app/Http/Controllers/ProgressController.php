<?php

namespace App\Http\Controllers;

use App\Models\Intention;
use App\Services\Progress\LoopProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The read-only progress dashboard. `index` lists the user's active loops as
 * metric cards; `show` (Task 3) drills into one owned loop's full metrics,
 * strategy journey, and rolling narrative. Pure read — every write stays on its
 * existing screen.
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

        return Inertia::render('progress/index', ['loops' => $loops]);
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
