<?php

namespace App\Services\Coach\Data;

use App\Services\Coach\Exceptions\CoachException;

/**
 * A provider-agnostic coaching response. `raw` keeps the untouched provider
 * payload for debugging / cost auditing.
 */
final readonly class CoachResponse
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $content,
        public string $model,
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public ?string $finishReason = null,
        public array $raw = [],
    ) {}

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    /**
     * Decode the content as JSON. Tolerates models that wrap JSON in prose or
     * ```json fences by extracting the first {...} / [...] block.
     *
     * @return array<mixed>
     *
     * @throws CoachException when no valid JSON can be parsed.
     */
    public function json(): array
    {
        $text = trim($this->content);

        // Strip Markdown code fences if present.
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
            $text = trim($text);
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            // Fall back to the first JSON object/array embedded in the text.
            if (preg_match('/(\{.*\}|\[.*\])/s', $text, $m) === 1) {
                $decoded = json_decode($m[1], true);
            }
        }

        if (! is_array($decoded)) {
            throw CoachException::invalidJson($this->content);
        }

        return $decoded;
    }
}
