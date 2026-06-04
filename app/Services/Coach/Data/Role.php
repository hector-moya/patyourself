<?php

namespace App\Services\Coach\Data;

/**
 * Conversation roles, normalized across providers.
 */
enum Role: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
}
