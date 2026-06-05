<?php

namespace App\Services\Coach\Drivers;

use App\Services\Coach\Contracts\CoachService;
use App\Services\Coach\Data\CoachRequest;
use App\Services\Coach\Data\CoachResponse;
use App\Services\Coach\Exceptions\CoachException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Anthropic Messages API driver — the first concrete CoachService. All calls
 * happen here, server-side; the API key never leaves the backend.
 */
class AnthropicCoachService implements CoachService
{
    /** Default Anthropic API version; override via services.anthropic.version. */
    private const DEFAULT_API_VERSION = '2023-06-01';

    /**
     * @param  array<string, mixed>  $config  The `services.anthropic` config plus shared coach defaults.
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly array $config,
    ) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function chat(CoachRequest $request): CoachResponse
    {
        $key = $this->config['key'] ?? null;

        if (empty($key)) {
            throw CoachException::missingCredentials($this->name());
        }

        $system = $request->resolveSystem();

        if ($request->json) {
            $jsonInstruction = 'Respond with a single valid JSON value and nothing else. No prose, no Markdown fences.';
            $system = $system === null ? $jsonInstruction : $system."\n\n".$jsonInstruction;
        }

        $payload = array_filter([
            'model' => $request->model ?? $this->config['model'],
            'max_tokens' => $request->maxTokens ?? (int) ($this->config['max_tokens'] ?? 1024),
            'temperature' => $request->temperature ?? (float) ($this->config['temperature'] ?? 0.7),
            'system' => $system,
            'messages' => $request->messagePayload(excludeSystem: true),
        ], static fn ($value) => $value !== null);

        $response = $this->http
            ->baseUrl(rtrim((string) $this->config['base_url'], '/'))
            ->withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => $this->config['version'] ?? self::DEFAULT_API_VERSION,
                'content-type' => 'application/json',
            ])
            ->timeout((int) ($this->config['timeout'] ?? 60))
            ->retry((int) ($this->config['retries'] ?? 2), 250, throw: false)
            ->post('/v1/messages', $payload);

        return $this->parse($response);
    }

    private function parse(Response $response): CoachResponse
    {
        if ($response->failed()) {
            throw CoachException::requestFailed(
                $this->name(),
                $response->status(),
                (string) $response->body(),
            );
        }

        $data = $response->json();

        // A 200 with a non-JSON (or empty) body is still unusable to us.
        if (! is_array($data)) {
            throw CoachException::emptyResponse($this->name());
        }

        // Concatenate all text blocks in the content array.
        $content = collect($data['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        if ($content === '') {
            throw CoachException::emptyResponse($this->name());
        }

        return new CoachResponse(
            content: $content,
            model: $data['model'] ?? (string) $this->config['model'],
            promptTokens: (int) ($data['usage']['input_tokens'] ?? 0),
            completionTokens: (int) ($data['usage']['output_tokens'] ?? 0),
            finishReason: $data['stop_reason'] ?? null,
            raw: $data,
        );
    }
}
