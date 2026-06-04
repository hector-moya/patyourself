<?php

namespace App\Services\Coach;

use App\Services\Coach\Contracts\CoachService;
use App\Services\Coach\Drivers\AnthropicCoachService;
use App\Services\Coach\Exceptions\CoachException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Manager;

/**
 * Resolves the active CoachService driver from config. Add new providers by
 * defining a create<Driver>Driver() method — the rest of the app keeps coding
 * against the CoachService contract.
 *
 * @method CoachService driver(string|null $driver = null)
 */
class CoachManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('services.coach.driver', 'anthropic');
    }

    public function createAnthropicDriver(): CoachService
    {
        return new AnthropicCoachService(
            $this->container->make(HttpFactory::class),
            $this->driverConfig('anthropic'),
        );
    }

    public function createOpenaiDriver(): CoachService
    {
        // Placeholder: the OpenAI driver is intentionally deferred. The first
        // concrete driver is Anthropic; this keeps the vendor swappable.
        throw CoachException::unsupportedDriver('openai');
    }

    /**
     * Merge a provider's config with the shared coach request defaults.
     *
     * @return array<string, mixed>
     */
    protected function driverConfig(string $provider): array
    {
        $shared = (array) $this->config->get('services.coach', []);
        $providerConfig = (array) $this->config->get("services.{$provider}", []);

        return array_merge($shared, $providerConfig);
    }
}
