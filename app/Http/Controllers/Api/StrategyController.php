<?php

namespace App\Http\Controllers\Api;

use App\Actions\ReviseStrategy;
use App\Http\Controllers\Controller;
use App\Http\Resources\StrategyResource;
use App\Models\Intention;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only versioned strategy timeline for a loop — the data behind the
 * history view on the loop detail screen. Oldest version first; nothing here
 * mutates, history is only ever appended to by {@see ReviseStrategy}.
 */
class StrategyController extends Controller
{
    public function index(Intention $intention): AnonymousResourceCollection
    {
        Gate::authorize('view', $intention);

        $strategies = $intention->strategies()->orderedByVersion()->get();

        return StrategyResource::collection($strategies);
    }
}
