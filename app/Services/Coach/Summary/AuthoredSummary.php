<?php

namespace App\Services\Coach\Summary;

use App\Services\Coach\Data\CoachResponse;

/**
 * A validated, LLM-authored rolling summary — the data the UpdateRollingSummary
 * action persists as a Summary snapshot. The data half of the "AI authors data"
 * split; carries no persistence concerns.
 */
final readonly class AuthoredSummary
{
    /**
     * @param  list<string>  $patterns
     */
    public function __construct(
        public string $content,
        public array $patterns,
        public string $model,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws SummaryException
     */
    public static function fromResponse(array $payload, CoachResponse $response): self
    {
        $data = PatternSummarySchema::validate($payload);

        return new self(
            content: (string) $data['content'],
            patterns: array_values(array_map('strval', $data['patterns'] ?? [])),
            model: $response->model,
        );
    }

    /**
     * Structured extras to persist on the Summary's metadata column.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'model' => $this->model,
            'patterns' => $this->patterns,
        ];
    }
}
