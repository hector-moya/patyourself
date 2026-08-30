<?php

namespace App\Http\Controllers;

use App\Actions\LogAction;
use App\Models\Occurrence;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Answers a reminder from the email, without a login.
 *
 * The app's only unauthenticated write. Protected by a signed URL that expires
 * in seven days, and effectively single-use: an occasion that already carries an
 * outcome is not written again, which also makes a double click correct.
 *
 * `failed` is deliberately absent. A failure has to carry the user's own stated
 * reason, and a one-click failure would either drop it or invent it — so the
 * mail deep-links into the app for that one.
 */
class QuickLogController extends Controller
{
    private const ONE_CLICK_OUTCOMES = ['completed', 'skipped'];

    public function __invoke(Occurrence $occurrence, string $outcome, LogAction $logAction): View
    {
        if (! in_array($outcome, self::ONE_CLICK_OUTCOMES, true)) {
            throw new NotFoundHttpException;
        }

        $occurrence->loadMissing(['action.intention.user', 'log']);

        // Idempotent by reading the record rather than by storing new state:
        // this is what gives the signed link single-use semantics, and it makes
        // a double click correct instead of a second write.
        if ($occurrence->isLogged()) {
            return view('quick-log', [
                'title' => $occurrence->action->title,
                'outcome' => $occurrence->log->outcome,
                'alreadyLogged' => true,
            ]);
        }

        $logAction->handle(
            $occurrence->action->intention->user,
            $occurrence->action,
            ['outcome' => $outcome],
            $occurrence,
        );

        return view('quick-log', [
            'title' => $occurrence->action->title,
            'outcome' => $outcome,
            'alreadyLogged' => false,
        ]);
    }
}
