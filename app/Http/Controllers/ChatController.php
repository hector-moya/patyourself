<?php

namespace App\Http\Controllers;

use App\Actions\RespondToChat;
use App\Http\Requests\ChatRequest;
use App\Http\Resources\IntentionResource;
use App\Services\Coach\Chat\ChatResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The chat home screen: renders the daily-driver thread, and the endpoint that
 * runs a user message through the coach and returns the reply plus any
 * structured action cards (LLM-authored Intention loops) to render inline.
 *
 * Action cards — both the loops seeded on load and the ones the coach authors
 * mid-conversation — share one shape (IntentionResource), so the UI renders
 * them identically.
 */
class ChatController extends Controller
{
    public function home(Request $request): Response
    {
        $intentions = $request->user()->intentions()
            ->active()
            ->with('activeStrategy')
            ->latest()
            ->get();

        return Inertia::render('coach', [
            'intentions' => IntentionResource::collection($intentions)->resolve(),
        ]);
    }

    public function store(ChatRequest $request, RespondToChat $respond): JsonResponse
    {
        $result = $respond->handle(
            $request->user(),
            $request->validated('message'),
            $request->history(),
        );

        return response()->json([
            'message' => $result->message,
            'cards' => $this->cards($result),
        ]);
    }

    /**
     * @return list<array{type: string, intention: array<string, mixed>}>
     */
    private function cards(ChatResult $result): array
    {
        if ($result->intention === null) {
            return [];
        }

        $result->intention->loadMissing('activeStrategy');

        return [[
            'type' => 'intention',
            'intention' => (new IntentionResource($result->intention))->resolve(),
        ]];
    }
}
