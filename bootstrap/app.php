<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Services\Coach\Chat\ChatException;
use App\Services\Coach\Exceptions\CoachException;
use App\Services\Coach\Exceptions\CoachQuotaException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

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

        // Coaching failures degrade gracefully for JSON clients: an out-of-budget
        // user gets 429 (distinguishable from a provider outage), and any other
        // coach/LLM failure gets 503 rather than a raw 500 stack trace.
        $exceptions->render(function (CoachException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return $e instanceof CoachQuotaException
                ? response()->json([
                    'message' => 'You have reached your daily coaching limit. Please try again later.',
                ], 429)
                : response()->json([
                    'message' => 'The coach is unavailable right now. Please try again in a moment.',
                ], 503);
        });

        $exceptions->render(function (ChatException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => 'The coach is unavailable right now. Please try again in a moment.',
            ], 503);
        });
    })->create();
