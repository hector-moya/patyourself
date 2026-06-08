<?php

namespace App\Services\Coach\Chat;

use App\Models\Intention;

/**
 * The outcome of a chat turn: the coach's message plus any persisted Intention
 * to render as an inline card.
 */
final readonly class ChatResult
{
    public function __construct(
        public string $message,
        public ?Intention $intention = null,
    ) {}
}
