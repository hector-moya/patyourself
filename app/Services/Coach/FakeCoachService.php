<?php

namespace App\Services\Coach;

use App\Services\Coach\Contracts\CoachService;
use App\Services\Coach\Data\CoachRequest;
use App\Services\Coach\Data\CoachResponse;

/**
 * A deterministic, network-free CoachService for tests and local development
 * without API keys. Queue canned responses with push(); otherwise it echoes a
 * default reply. Records every request for assertions.
 */
class FakeCoachService implements CoachService
{
    /** @var list<CoachResponse> */
    private array $queue = [];

    /** @var list<CoachRequest> */
    public array $requests = [];

    public function name(): string
    {
        return 'fake';
    }

    /**
     * Queue the next response. Pass a string for a plain reply, or a
     * CoachResponse for full control.
     */
    public function push(CoachResponse|string $response): self
    {
        $this->queue[] = $response instanceof CoachResponse
            ? $response
            : new CoachResponse(content: $response, model: 'fake');

        return $this;
    }

    /**
     * Queue a JSON response (encodes the given value).
     */
    public function pushJson(mixed $value): self
    {
        return $this->push((string) json_encode($value));
    }

    public function chat(CoachRequest $request): CoachResponse
    {
        $this->requests[] = $request;

        if ($this->queue !== []) {
            return array_shift($this->queue);
        }

        return new CoachResponse(
            content: 'This is a fake coach response.',
            model: 'fake',
            promptTokens: 0,
            completionTokens: 0,
            finishReason: 'stop',
        );
    }

    /**
     * The most recent request the fake received, or null if it was never
     * called. Handy for asserting what the app sent the coach.
     */
    public function lastRequest(): ?CoachRequest
    {
        return $this->requests === [] ? null : $this->requests[array_key_last($this->requests)];
    }
}
