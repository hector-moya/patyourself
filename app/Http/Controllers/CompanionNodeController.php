<?php

namespace App\Http\Controllers;

use App\Actions\HarvestNode;
use App\Actions\MeetNode;
use App\Models\Companion;
use App\Models\User;
use App\Services\Companion\CompanionEconomyException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Touching something in the clearing.
 *
 * One route for both halves of the gesture, because from the user's side it IS
 * one gesture: you click the reeds. What happens depends on whether Blob knows
 * what reeds are, and that difference is the whole of F1's discovery mechanic.
 *
 * WITHOUT THE SKILL, nothing fails and nothing shows a lock. Blob walks over,
 * turns it over and puts it down again — and that encounter is what puts the
 * skill in the bag's list. The first time it happens, the bag opens on it: a
 * panel would have made that visible and a modal hides it, so this is what pays
 * for the modal. Once, as the response to a click that was just made.
 *
 * WITH THE SKILL, Blob gathers what it can carry. The remainder stays standing;
 * nothing is ever destroyed.
 *
 * WITH THE SKILL BUT WITHOUT THE TOOL, Blob looks and leaves it. Nothing is
 * revealed — the skill list already has the row and the bag already has the
 * recipe — so nothing opens, and the line says what Blob is carrying rather
 * than what the reader should go and build.
 *
 * Every outcome here is an ordinary state of the world rather than an error. A
 * full bag is a reason to build the next container, an empty node is a fact
 * about the clearing, and both come back as a line in Blob's own voice.
 */
class CompanionNodeController extends Controller
{
    public function store(Request $request, string $node): RedirectResponse
    {
        /** @var array<string, array<string, mixed>> $authored */
        $authored = (array) config('companion.nodes', []);

        abort_unless(array_key_exists($node, $authored), Status::HTTP_NOT_FOUND);

        $user = $request->user();
        $entry = $authored[$node];
        $name = Companion::nameFor($user);

        if ($this->hasSkill($user, (string) $entry['skill'])) {
            $tool = (string) ($entry['tool'] ?? '');

            // Checked here as well as in HarvestNode, for the same reason the
            // skill is: the action's throw is the guarantee, and this is the
            // copy. Letting the action throw and catching it in `gather()`
            // would announce a full bag, which is a true sentence about the
            // wrong thing.
            if ($tool !== '' && ! $this->hasTool($user, $tool)) {
                return back()->with(
                    CompanionController::SAID_KEY,
                    $this->say($entry['blunt'] ?? '', $name),
                );
            }

            return $this->gather($user, $node, $entry, $name);
        }

        // Whether this is the FIRST look is the only thing that decides if the
        // bag opens: meeting a node Blob has already met reveals nothing, so it
        // interrupts nothing.
        $firstLook = $this->neverMet($user, $node);

        app(MeetNode::class)->handle($user, $node);

        return back()
            ->with(CompanionController::SAID_KEY, $this->say($entry['met'] ?? '', $name))
            ->with(CompanionController::REVEALED_KEY, $firstLook);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function gather(User $user, string $node, array $entry, string $name): RedirectResponse
    {
        try {
            $moved = app(HarvestNode::class)->handle($user, $node);
        } catch (CompanionEconomyException) {
            // The bag is full. Said plainly, naming where the thing stayed,
            // because it is still standing there and that is the point.
            return back()->with(CompanionController::SAID_KEY, $this->say($entry['full'] ?? '', $name));
        }

        $line = $moved === 0
            ? $this->say($entry['empty'] ?? '', $name)
            : str_replace('{count}', (string) $moved, $this->say($entry['took'] ?? '', $name));

        return back()->with(CompanionController::SAID_KEY, $line);
    }

    private function hasSkill(User $user, string $skill): bool
    {
        return Companion::query()
            ->where('user_id', $user->id)
            ->whereHas('skills', fn ($query) => $query->where('name', $skill))
            ->exists();
    }

    private function hasTool(User $user, string $tool): bool
    {
        return Companion::query()
            ->where('user_id', $user->id)
            ->whereHas('items', fn ($query) => $query->where('item', $tool))
            ->exists();
    }

    private function neverMet(User $user, string $node): bool
    {
        return Companion::query()
            ->where('user_id', $user->id)
            ->whereHas('nodes', fn ($query) => $query->where('node', $node))
            ->doesntExist();
    }

    /** Authored copy, with the companion's own name in it. */
    private function say(string $line, string $name): string
    {
        return str_replace('{name}', $name, $line);
    }
}
