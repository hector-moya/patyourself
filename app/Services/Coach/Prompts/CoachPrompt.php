<?php

namespace App\Services\Coach\Prompts;

/**
 * A single, versioned system prompt. `version` is recorded on whatever the coach
 * authors with it, so when a prompt changes we can tell which artifacts came
 * from which wording.
 */
final readonly class CoachPrompt
{
    public function __construct(
        public string $name,
        public string $version,
        public string $system,
    ) {}
}
