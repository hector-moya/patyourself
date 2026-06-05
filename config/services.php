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
    | LLM Providers (CoachService drivers)
    |--------------------------------------------------------------------------
    |
    | Credentials for the provider-agnostic CoachService. Keys live only in
    | .env (gitignored); .env.example ships placeholders. Code against the
    | CoachService interface so the active provider stays swappable.
    |
    */

    'coach' => [
        'driver' => env('COACH_DRIVER', 'anthropic'),

        // Shared request defaults, applied unless a CoachRequest overrides them.
        'max_tokens' => (int) env('COACH_MAX_TOKENS', 1024),
        'temperature' => (float) env('COACH_TEMPERATURE', 0.7),
        'timeout' => (int) env('COACH_TIMEOUT', 60),
        'retries' => (int) env('COACH_RETRIES', 2),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    ],

];
