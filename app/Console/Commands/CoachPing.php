<?php

namespace App\Console\Commands;

use App\Ai\Agents\Coach;
use App\Services\Coach\Exceptions\CoachException;
use Illuminate\Console\Command;
use Laravel\Ai\Exceptions\AiException;

/**
 * Smoke-test the Coach SDK agent end-to-end from the CLI — the quickest way to
 * confirm credentials and connectivity before wiring the coach into the app.
 * Sends one tiny prompt and prints the reply plus token usage.
 *
 * Runs unauthenticated; GuardCoachUsage passes through unmetered with no user.
 */
class CoachPing extends Command
{
    protected $signature = 'coach:ping {prompt=Say hello in five words or fewer.}';

    protected $description = 'Send a one-off prompt to the Coach agent to verify it works end-to-end';

    public function handle(): int
    {
        $this->components->info('Pinging the Coach agent…');

        try {
            $response = (new Coach)->prompt((string) $this->argument('prompt'));
        } catch (CoachException|AiException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line($response->text);
        $this->newLine();
        $this->components->twoColumnDetail('model', $response->meta->model ?? 'unknown');
        $this->components->twoColumnDetail(
            'tokens (prompt / completion)',
            $response->usage->promptTokens.' / '.$response->usage->completionTokens,
        );

        return self::SUCCESS;
    }
}
