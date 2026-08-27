<?php

namespace App\Http\Controllers;

use App\Services\Companion\CompanionResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Blob's own screen: Blob at full size, and a plain list of what it has and
 * when each arrived.
 *
 * The list is history, not a trophy case. Nothing here names a locked slot, a
 * remaining count or what comes next — the moment the screen previews the next
 * unlock it becomes a checklist, and a checklist is a thing to fall behind on.
 *
 * The whole page is a read: {@see CompanionResolver} derives it from the record
 * on every request, so there is no state here to get out of step.
 */
class CompanionController extends Controller
{
    public function index(Request $request, CompanionResolver $resolver): Response
    {
        return Inertia::render('companion', [
            'companion' => $resolver->forUser($request->user())->toArray(),
        ]);
    }
}
