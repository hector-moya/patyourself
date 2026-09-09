<?php

use App\Mcp\Servers\PatYourSelfServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Middleware\CheckToken;

// OAuth 2.1 discovery + dynamic client registration — what lets claude.ai
// register itself as a client and walk the user through authorization.
//
// Throttled per IP because this group is the app's only UNAUTHENTICATED
// surface that writes: `POST oauth/register` mints an OAuth client row for
// anyone who asks, which is what "dynamic client registration" means. The
// authenticated endpoint below is the one the ticket named, but this is the
// one an anonymous caller can reach.
//
// 30/minute is far above what connecting a client costs — discovery, one
// registration, one authorization, one token exchange, and refreshes
// thereafter — and far below what a script would want. Deliberately generous
// so that reconnecting a stale connector, which walks the whole dance again,
// cannot trip it.
Route::middleware('throttle:30,1')->group(function (): void {
    Mcp::oauthRoutes();
});

// Keyed per user rather than per IP, because `throttle` keys on the
// authenticated user when there is one — so one noisy client cannot spend
// another's budget.
//
// 120/minute is two per second sustained. A single conversation can call
// several tools in a burst and then sit idle, so the limit has to clear a
// burst comfortably; what it bounds is a loop, not a session. This endpoint
// carries ten writing tools, which is why it gets a limit at all: the design
// listed rate limiting as out of scope when the server was five read-only
// tools, and that premise stopped holding.
Mcp::web('/mcp', PatYourSelfServer::class)
    ->middleware(['auth:api', CheckToken::using('mcp:use'), 'throttle:120,1']);
