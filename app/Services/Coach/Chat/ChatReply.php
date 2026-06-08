<?php

namespace App\Services\Coach\Chat;

use App\Services\Coach\Authoring\AuthoredIntention;

/**
 * The coach's structured chat response: a conversational message plus an
 * optional Intention card the UI renders inline. Data only — the RespondToChat
 * action persists any card.
 */
final readonly class ChatReply
{
    public function __construct(
        public string $message,
        public ?AuthoredIntention $intention = null,
    ) {}
}
