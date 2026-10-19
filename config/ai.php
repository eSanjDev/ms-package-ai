<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Service Base URL
    |--------------------------------------------------------------------------
    | The base URL of the Esanj AI microservice, without a trailing slash.
    */
    'base_url' => env('AI_SERVICE_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | OAuth Client Credentials
    |--------------------------------------------------------------------------
    | The service credentials used to obtain an access token. The token itself
    | is issued by the OAuth server configured for the auth-bridge package
    | (esanj.auth_bridge.base_url) via the client_credentials grant, and is then
    | sent as a Bearer token to the AI service.
    */
    'client_id' => env('AI_CLIENT_ID'),
    'client_secret' => env('AI_CLIENT_SECRET'),
    'scope' => env('AI_SCOPE', '*'),

    /*
    |--------------------------------------------------------------------------
    | Retry Policy
    |--------------------------------------------------------------------------
    | attempts : total number of attempts (1 = no retry).
    | sleep_ms : milliseconds to wait between retries.
    */
    'retry' => [
        'attempts' => (int) env('AI_RETRY_ATTEMPTS', 3),
        'sleep_ms' => (int) env('AI_RETRY_SLEEP_MS', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout (seconds)
    |--------------------------------------------------------------------------
    | Running a workflow waits for every AI agent in it to answer, so keep this
    | well above the slowest workflow you run.
    */
    'timeout' => (int) env('AI_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    | The log channel used by the client. null = the application default channel.
    */
    'logging' => [
        'channel' => env('AI_LOG_CHANNEL'),
    ],
];
