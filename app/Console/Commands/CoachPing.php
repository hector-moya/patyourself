<?php

namespace App\Console\Commands;

use App\Services\Coach\Contracts\CoachService;
use App\Services\Coach\Data\CoachRequest;
use App\Services\Coach\Exceptions\CoachException;
use Illuminate\Console\Command;

/**
 * Smoke-test the configured CoachService driver end-to-end from the CLI —
 * the quickest way to confirm credentials and connectivity before wiring the
 * coach into the app. Sends one tiny prompt and prints the reply plus token
 * usage.
 */
class CoachPing extends Command
{
    protected $signature = 'coach:ping {prompt=Say hello in five words or fewer.}';

    protected $description = 'Send a one-off prompt to the configured coach driver to verify it works';

    public function handle(CoachService $coach): int
    {
        $this->components->info("Pinging the [{$coach->name()}] coach driver…");

        try {
            $response = $coach->chat(CoachRequest::prompt((string) $this->argument('prompt')));
        } catch (CoachException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line($response->content);
        $this->newLine();
        $this->components->twoColumnDetail('model', $response->model);
        $this->components->twoColumnDetail('tokens (prompt / completion)', "{$response->promptTokens} / {$response->completionTokens}");

        return self::SUCCESS;
    }
}
