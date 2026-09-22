<?php

namespace App\Http\Controllers;

use App\Actions\StashItem;
use App\Actions\UnstashItem;
use App\Models\Companion;
use App\Services\Companion\CompanionEconomyException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Moving a stack between the bag and the chest.
 *
 * Posted from inside the bag, so the bag stays up: the point of the press is to
 * watch the row move from one list to the other.
 *
 * ONE ROUTE FOR BOTH DIRECTIONS, because it is one gesture with a sign on it.
 * Two routes would be two names for the same act, and the two copy lines would
 * drift apart the first time one was reworded.
 *
 * Only carried categories are routable at all. A tool is on the belt and a
 * container is the room itself, so neither is ever offered here — a request
 * naming one is malformed rather than a state of the world, and it is a 404
 * rather than a line in Blob's voice.
 */
class CompanionStashController extends Controller
{
    /** Only the entries in `companion.bag` that can move between the two lists. */
    private function movable(): array
    {
        /** @var list<string> $carried */
        $carried = (array) config('companion.capacity.carried', []);

        return array_keys(array_filter(
            (array) config('companion.bag', []),
            static fn (array $item): bool => in_array((string) ($item['category'] ?? ''), $carried, true),
        ));
    }

    public function store(Request $request, StashItem $stash, UnstashItem $unstash): RedirectResponse
    {
        $validated = $request->validate([
            'item' => ['required', 'string'],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'amount' => ['nullable', 'integer', 'min:1'],
        ]);

        $item = (string) $validated['item'];

        abort_unless(in_array($item, $this->movable(), true), Status::HTTP_NOT_FOUND);

        $user = $request->user();
        $label = (string) (config("companion.bag.{$item}.label") ?? $item);
        $amount = $validated['amount'] ?? null;

        try {
            $moved = $validated['direction'] === 'in'
                ? $stash->handle($user, $item, $amount)
                : $unstash->handle($user, $item, $amount);
        } catch (CompanionEconomyException) {
            // The bag is full. Worded as a node's own refusal is: it names
            // where the thing stays, because nothing is destroyed by a press
            // that could not complete.
            return back()
                ->with(CompanionController::SAID_KEY, str_replace(
                    '{label}',
                    $label,
                    (string) config('companion.stash.full'),
                ))
                ->with(CompanionController::STAY_KEY, true);
        }

        // Nothing was there. Said by saying nothing: a line about a stack that
        // did not exist would be the app narrating a press that did nothing.
        if ($moved === 0) {
            return back()->with(CompanionController::STAY_KEY, true);
        }

        return back()
            ->with(CompanionController::SAID_KEY, str_replace(
                ['{name}', '{count}', '{label}'],
                [Companion::nameFor($user), (string) $moved, $label],
                (string) config("companion.stash.{$validated['direction']}"),
            ))
            ->with(CompanionController::STAY_KEY, true);
    }
}
