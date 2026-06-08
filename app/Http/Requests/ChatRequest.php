<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a chat turn: the user's message and any prior conversation turns.
 */
class ChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is behind the auth middleware; any signed-in user may chat.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:2000'],
            'history' => ['nullable', 'array', 'max:50'],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:4000'],
        ];
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    public function history(): array
    {
        /** @var list<array{role: string, content: string}> $history */
        $history = $this->validated('history') ?? [];

        return $history;
    }
}
