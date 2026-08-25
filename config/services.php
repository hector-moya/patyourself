<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Coach (LLM cost guard + rate limiter)
    |--------------------------------------------------------------------------
    |
    | LLM provider credentials live in config/ai.php (read by the laravel/ai
    | package). The settings here are app-level concerns only: the rolling
    | per-user token budget enforced by GuardCoachUsage middleware, and the
    | per-minute rate limit applied to the chat endpoint.
    |
    */

    'coach' => [
        // Cost guard: rolling-24h per-user token budget (prompt + completion)
        // across all LLM calls. 0 disables the cap. Over budget → HTTP 429.
        'daily_token_budget' => (int) env('COACH_DAILY_TOKEN_BUDGET', 200000),

        // Rate limit: max coach requests per user per minute (the `coach`
        // limiter applied to the chat endpoint). 0 disables it.
        'rate_per_minute' => (int) env('COACH_RATE_PER_MINUTE', 20),
    ],

];
