<?php

namespace App\Http\Controllers;

use App\Models\CompanionRemark;
use App\Services\Companion\CompanionRemarks;
use App\Services\Companion\CompanionResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Blob's own screen: Blob at full size, a plain list of what it has and when
 * each arrived, and — when there is one — something Blob has to say.
 *
 * The list is history, not a trophy case. Nothing here names a locked slot, a
 * remaining count or what comes next — the moment the screen previews the next
 * unlock it becomes a checklist, and a checklist is a thing to fall behind on.
 *
 * The Blob half of the page is a pure read: {@see CompanionResolver} derives it
 * from the record on every request. The remark is the one thing here that
 * writes, and it writes one id to the session so the next visit can avoid
 * repeating itself.
 *
 * A remark is drawn only once Blob exists. Before that there is nobody to relay
 * it, the screen renders none, and picking one anyway would record it as shown
 * when it never was.
 */
class CompanionController extends Controller
{
    public function index(Request $request, CompanionResolver $resolver, CompanionRemarks $remarks): Response
    {
        $user = $request->user();
        $companion = $resolver->forUser($user);

        $remark = $companion->stageIndex() === 0
            ? null
            : $remarks->nextFor($user, $request->session()->get(CompanionRemarks::SESSION_KEY));

        if ($remark instanceof CompanionRemark) {
            $request->session()->put(CompanionRemarks::SESSION_KEY, $remark->id);
        }

        return Inertia::render('companion', [
            'companion' => $companion->toArray(),
            // Null is the ordinary case, and the screen renders nothing for it.
            'remark' => $remark?->body,
        ]);
    }
}
