<?php

namespace App\Services\Coach\Contracts;

use App\Services\Coach\Data\CoachRequest;
use App\Services\Coach\Data\CoachResponse;
use App\Services\Coach\Exceptions\CoachException;

/**
 * Provider-agnostic coaching engine. Concrete drivers (Anthropic, OpenAI, ...)
 * implement this; the rest of the app codes against the interface so the LLM
 * vendor stays swappable. All implementations run server-side only.
 */
interface CoachService
{
    /**
     * Send a coaching request to the underlying provider and return a
     * normalized response.
     *
     * @throws CoachException
     */
    public function chat(CoachRequest $request): CoachResponse;

    /**
     * The driver's identifier (e.g. "anthropic"). Useful for logging and
     * cost attribution.
     */
    public function name(): string;
}
