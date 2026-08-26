<?php

namespace App\Actions;

use App\Models\Intention;
use App\Models\Note;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Records an observation against a loop. The only place a note is written.
 *
 * The body is stored verbatim, for the same reason a failure reason is: it is
 * the user's own account of their own behaviour, and tidying it destroys the
 * thing that made it worth keeping.
 */
final readonly class LogNote
{
    public function handle(Intention $loop, string $body, ?CarbonInterface $notedAt = null): Note
    {
        return $loop->notes()->create([
            'body' => $body,
            'noted_at' => $notedAt ?? Date::now(),
        ]);
    }
}
