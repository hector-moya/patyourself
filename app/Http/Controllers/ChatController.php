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
use Laravel\Ai\Models\ConversationMessage;

/**
 * The chat home screen: renders the daily-driver thread, and the endpoint that
 * runs a user message through the coach and returns the reply plus any
 * structured action cards (LLM-authored Intention loops) to render inline.
 *
 * The thread is hydrated from the server-side durable conversation so it
 * survives page reloads without the client managing history.
 *
 * Column names from the agent_conversation_messages migration:
 *   role (string 25) — 'user' or 'assistant'
 *   content (text) — the message text
 */
class ChatController extends Controller
{
    public function home(Request $request): Response
    {
        $user = $request->user();

        $intentions = $user->intentions()
            ->active()
            ->with(['activeStrategy', 'activeAction'])
            ->latest()
            ->get();

        return Inertia::render('coach', [
            'intentions' => IntentionResource::collection($intentions)->resolve(),
            'thread' => $this->recentThread($user),
            'userTimezone' => $user->timezone,
        ]);
    }

    public function store(ChatRequest $request, RespondToChat $respond): JsonResponse
    {
        $result = $respond->handle(
            $request->user(),
            $request->validated('message'),
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

        $result->intention->loadMissing(['activeStrategy', 'activeAction']);

        return [[
            'type' => 'intention',
            'intention' => (new IntentionResource($result->intention))->resolve(),
        ]];
    }

    /**
     * The user's recent coach conversation, oldest first, mapped to the
     * frontend's ChatMessage shape so the thread survives reloads.
     *
     * @return list<array{id: string, role: string, text: string}>
     */
    private function recentThread($user): array
    {
        $conversation = $user->conversations()
            ->latest('updated_at')
            ->first();

        if ($conversation === null) {
            return [];
        }

        return $conversation->messages()
            ->latest('id')
            ->limit(50)
            ->get()
            ->sortBy('id')
            ->values()
            ->map(fn (ConversationMessage $m): array => [
                'id' => 'h'.$m->id,
                'role' => $m->role === 'user' ? 'user' : 'coach',
                'text' => (string) $m->content,
            ])
            ->values()
            ->all();
    }
}
