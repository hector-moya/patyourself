<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a chat turn. History is now stored server-side in the durable
 * conversation; only the current message is sent by the client.
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
        ];
    }
}
