<?php

namespace App\Services\Authoring;

use App\Actions\PersistAuthoredIntention;
use App\Mcp\Tools\CreateLoopTool;
use App\Models\Intention;
use App\Models\Strategy;

/**
 * A validated, structured Intention authored via the MCP `create-loop` tool —
 * the data half of the "authored data in, UI renders it" split. It carries no
 * persistence concerns; {@see PersistAuthoredIntention} turns it
 * into Intention / Strategy records.
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
        public ?string $promptVersion = null,
        public ?AuthoredAction $action = null,
    ) {}

    /**
     * Build the DTO from a structured payload.
     *
     * Used by {@see CreateLoopTool} so the mapping and guards
     * live in one place.
     *
     * @param  array<string, mixed>  $data  The tool call's structured input array.
     *
     * @throws AuthoringException when required fields are missing or invalid.
     * @throws IntentionAuthoringException when the schema is structurally invalid.
     */
    public static function fromStructured(array $data, string $model, ?string $promptVersion = null): self
    {
        $validTypes = [Intention::TYPE_BUILD, Intention::TYPE_BREAK];

        $title = is_string($data['title'] ?? null) ? trim($data['title']) : '';
        $type = is_string($data['type'] ?? null) ? trim($data['type']) : '';
        $cue = is_string($data['cue'] ?? null) ? trim($data['cue']) : '';
        $craving = is_string($data['craving'] ?? null) ? trim($data['craving']) : '';
        $response = is_string($data['response'] ?? null) ? trim($data['response']) : '';
        $reward = is_string($data['reward'] ?? null) ? trim($data['reward']) : '';

        if (
            $title === '' ||
            $type === '' || ! in_array($type, $validTypes, true) ||
            $cue === '' ||
            $craving === '' ||
            $response === '' ||
            $reward === ''
        ) {
            throw AuthoringException::emptyResponse();
        }

        $authoredStrategy = null;
        if (isset($data['strategy']) && is_array($data['strategy'])) {
            $strategyData = $data['strategy'];
            $validPoints = [
                Strategy::POINT_CUE,
                Strategy::POINT_CRAVING,
                Strategy::POINT_RESPONSE,
                Strategy::POINT_REWARD,
            ];

            $interventionPoint = is_string($strategyData['intervention_point'] ?? null)
                ? trim($strategyData['intervention_point'])
                : '';
            $approach = is_string($strategyData['approach'] ?? null) ? trim($strategyData['approach']) : '';

            if (
                $interventionPoint === '' ||
                ! in_array($interventionPoint, $validPoints, true) ||
                $approach === ''
            ) {
                throw AuthoringException::emptyResponse();
            }

            $authoredStrategy = new AuthoredStrategy(
                interventionPoint: $interventionPoint,
                approach: $approach,
                rationale: isset($strategyData['rationale']) ? trim((string) $strategyData['rationale']) : null,
                promptVersion: $promptVersion,
            );
        }

        $authoredAction = AuthoredAction::fromStructured(
            is_array($data['action'] ?? null) ? $data['action'] : null,
        );

        return new self(
            title: $title,
            description: isset($data['description']) ? (($d = trim((string) $data['description'])) !== '' ? $d : null) : null,
            type: $type,
            cue: $cue,
            craving: $craving,
            response: $response,
            reward: $reward,
            confidence: isset($data['confidence']) ? (float) $data['confidence'] : null,
            tags: array_values(array_map('strval', $data['tags'] ?? [])),
            strategy: $authoredStrategy,
            model: $model,
            promptVersion: $promptVersion,
            action: $authoredAction,
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
            'prompt_version' => $this->promptVersion,
            'confidence' => $this->confidence,
            'tags' => $this->tags === [] ? null : $this->tags,
        ], static fn ($value): bool => $value !== null);
    }
}
