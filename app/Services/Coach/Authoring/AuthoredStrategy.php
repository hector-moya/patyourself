<?php

namespace App\Services\Coach\Authoring;

/**
 * The initial intervention the coach proposes alongside a freshly authored
 * Intention. Seeds version 1 of the loop's strategy; the versioned
 * stack/restrategize logic lives elsewhere.
 */
final readonly class AuthoredStrategy
{
    public function __construct(
        public string $interventionPoint,
        public string $approach,
        public ?string $rationale = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  A validated `strategy` sub-array.
     */
    public static function fromValidated(array $data): self
    {
        return new self(
            interventionPoint: (string) $data['intervention_point'],
            approach: (string) $data['approach'],
            rationale: isset($data['rationale']) ? (string) $data['rationale'] : null,
        );
    }
}
