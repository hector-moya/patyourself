<?php

namespace App\Http\Controllers;

use App\Actions\RespondToChat;
use App\Http\Requests\ChatRequest;
use App\Models\Intention;
use App\Services\Coach\Chat\ChatResult;
use Illuminate\Http\JsonResponse;

/**
 * The chat home screen endpoint: takes a user message, runs it through the
 * coach, and returns the reply plus any structured action cards (authored
 * Intention loops) to render inline.
 */
class ChatController extends Controller
{
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

        return [[
            'type' => 'intention',
            'intention' => $this->intentionCard($result->intention),
        ]];
    }

    /**
     * A lightweight inline card payload. Full API resources arrive in Task 16.
     *
     * @return array<string, mixed>
     */
    private function intentionCard(Intention $intention): array
    {
        $strategy = $intention->activeStrategy;

        return [
            'id' => $intention->id,
            'title' => $intention->title,
            'description' => $intention->description,
            'type' => $intention->type,
            'status' => $intention->status,
            'cue' => $intention->cue,
            'craving' => $intention->craving,
            'response' => $intention->response,
            'reward' => $intention->reward,
            'metadata' => $intention->metadata,
            'strategy' => $strategy === null ? null : [
                'intervention_point' => $strategy->intervention_point,
                'approach' => $strategy->approach,
                'rationale' => $strategy->rationale,
            ],
        ];
    }
}
