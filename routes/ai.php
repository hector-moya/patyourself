<?php

use App\Mcp\Servers\PatYourSelfServer;
use Laravel\Mcp\Facades\Mcp;

// OAuth 2.1 discovery + dynamic client registration — what lets claude.ai
// register itself as a client and walk the user through authorization.
Mcp::oauthRoutes();

Mcp::web('/mcp', PatYourSelfServer::class)
    ->middleware('auth:api');
