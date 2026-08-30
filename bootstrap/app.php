<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Exclude verbatim fields from TrimStrings middleware. These arrive as HTTP form
        // input and are stored verbatim (never trimmed, squished, or sentence-cased) because
        // they are the raw material for strategy rewrites.
        //
        // Genuine cases: `reason` (outcome-logging routes), `note` (verdict route),
        // `approach` / `rationale` / `supersedes_reason` (start-experiment route), and
        // `body` (notes route).
        // See tests/Feature/ActionLogWebTest.php::test_the_reason_is_stored_verbatim,
        // tests/Feature/Experiments/StartExperimentWebTest.php::test_the_approach_rationale_and_supersedes_reason_are_stored_verbatim
        // and tests/Feature/Notes/LogNoteWebTest.php::test_the_body_is_stored_verbatim
        // for end-to-end verification.
        //
        // `content` is listed defensively: the write-reflection MCP tool bypasses this
        // middleware entirely (HttpTransport feeds raw JSON-RPC body, not parsed input bag).
        // But a future web route writing reflections would benefit from the protection.
        //
        // When adding a new verbatim field: use its REQUEST field name here (the name
        // in the form payload), not the database column name—they do not always match.
        $middleware->trimStrings(except: ['note', 'reason', 'content', 'approach', 'rationale', 'supersedes_reason', 'body']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A quick-log link that is invalid or has passed its seven-day window
        // must read as "this link has expired," never a stack trace and never a
        // redirect to login carrying the intended URL — there is no session to
        // send it back to. Status stays 403; only the body changes.
        $exceptions->render(function (InvalidSignatureException $e, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            return response()->view('errors.link-expired', status: 403);
        });
    })->create();
