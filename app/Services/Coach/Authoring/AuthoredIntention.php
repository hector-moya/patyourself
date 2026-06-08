<?php

namespace App\Services\Coach\Authoring;

use App\Services\Coach\Data\CoachResponse;

/**
 * A validated, structured Intention authored by the LLM — the data half of the
 * "AI authors data, UI renders it" split. It carries no persistence concerns;
 * the AuthorIntention action turns it into Intention / Strategy records.
 */
final readonly class AuthoredIntention
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $title,
        public ?string $description,
        public string $type,
        public string $cue,
        public string $craving,
        public string $response,
        public string $reward,
        public ?float $confidence,
        public array $tags,
        public ?AuthoredStrategy $strategy,
        public string $model,
    ) {}

    /**
     * Validate a decoded coach payload against the schema and build the DTO.
     *
     * @param  array<string, mixed>  $payload  The model's decoded JSON.
     *
     * @throws IntentionAuthoringException
     */
    public static function fromResponse(array $payload, CoachResponse $response): self
    {
        $data = IntentionSchema::validate($payload);

        return new self(
            title: (string) $data['title'],
            description: isset($data['description']) ? (string) $data['description'] : null,
            type: (string) $data['type'],
            cue: (string) $data['cue'],
            craving: (string) $data['craving'],
            response: (string) $data['response'],
            reward: (string) $data['reward'],
            confidence: isset($data['confidence']) ? (float) $data['confidence'] : null,
            tags: array_values(array_map('strval', $data['tags'] ?? [])),
            strategy: isset($data['strategy'])
                ? AuthoredStrategy::fromValidated($data['strategy'])
                : null,
            model: $response->model,
        );
    }

    /**
     * AI-authored extras to persist on the Intention's metadata column. Nulls
     * are stripped so the column stays clean.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return array_filter([
            'authored_by' => $this->model,
            'confidence' => $this->confidence,
            'tags' => $this->tags === [] ? null : $this->tags,
        ], static fn ($value): bool => $value !== null);
    }
}
