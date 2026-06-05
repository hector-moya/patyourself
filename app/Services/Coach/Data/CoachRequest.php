<?php

namespace App\Services\Coach\Data;

/**
 * A provider-agnostic coaching request. Drivers translate it into their own
 * wire format. Per-request overrides (model, temperature, maxTokens) fall back
 * to driver/config defaults when null.
 */
final readonly class CoachRequest
{
    /**
     * @param  list<Message>  $messages  Conversation turns (excluding system).
     * @param  string|null  $system  System prompt / instructions.
     * @param  bool  $json  Hint the model to return JSON only.
     * @param  array<string, mixed>  $metadata  Caller context (not sent to provider).
     */
    public function __construct(
        public array $messages,
        public ?string $system = null,
        public ?string $model = null,
        public ?float $temperature = null,
        public ?int $maxTokens = null,
        public bool $json = false,
        public array $metadata = [],
    ) {}

    /**
     * Convenience: a single user prompt with an optional system prompt. The
     * model/temperature/maxTokens overrides fall back to driver defaults when
     * left null, exactly like the full constructor.
     */
    public static function prompt(
        string $prompt,
        ?string $system = null,
        bool $json = false,
        ?string $model = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
    ): self {
        return new self(
            messages: [Message::user($prompt)],
            system: $system,
            model: $model,
            temperature: $temperature,
            maxTokens: $maxTokens,
            json: $json,
        );
    }

    /**
     * Messages serialized for a provider, optionally excluding system-role
     * turns (providers that take the system prompt as a separate field).
     *
     * @return list<array{role: string, content: string}>
     */
    public function messagePayload(bool $excludeSystem = true): array
    {
        return array_values(array_map(
            static fn (Message $m): array => $m->toArray(),
            array_filter(
                $this->messages,
                static fn (Message $m): bool => ! $excludeSystem || $m->role !== Role::System,
            ),
        ));
    }

    /**
     * Effective system prompt: the explicit system field merged with any
     * system-role messages, in order.
     */
    public function resolveSystem(): ?string
    {
        $parts = [];

        if ($this->system !== null && $this->system !== '') {
            $parts[] = $this->system;
        }

        foreach ($this->messages as $message) {
            if ($message->role === Role::System) {
                $parts[] = $message->content;
            }
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }
}
