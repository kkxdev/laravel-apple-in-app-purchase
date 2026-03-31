<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | Force a specific environment: "sandbox" or "production".
    | When set to null the package auto-detects from the validated receipt
    | (legacy path) or uses "production" for App Store Server API calls.
    |
    */
    'environment' => env('APPLE_IAP_ENVIRONMENT', 'production'),

    /*
    |--------------------------------------------------------------------------
    | App Credentials
    |--------------------------------------------------------------------------
    */
    'credentials' => [
        // Your app's bundle identifier (e.g. "com.example.myapp")
        'bundle_id' => env('APPLE_IAP_BUNDLE_ID'),

        // Shared secret for legacy receipt validation (App-Specific or Master)
        'shared_secret' => env('APPLE_IAP_SHARED_SECRET'),

        // App Store Server API credentials (StoreKit 2)
        'key_id'     => env('APPLE_IAP_KEY_ID'),
        'issuer_id'  => env('APPLE_IAP_ISSUER_ID'),

        // Absolute path to the .p8 private key file  OR  the key contents directly
        'private_key_path' => env('APPLE_IAP_PRIVATE_KEY_PATH'),
        'private_key'      => env('APPLE_IAP_PRIVATE_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | API URLs
    |--------------------------------------------------------------------------
    */
    'urls' => [
        'receipt_validation' => [
            'production' => 'https://buy.itunes.apple.com/verifyReceipt',
            'sandbox'    => 'https://sandbox.itunes.apple.com/verifyReceipt',
        ],
        'server_api' => [
            'production' => 'https://api.storekit.itunes.apple.com',
            'sandbox'    => 'https://api.storekit-sandbox.itunes.apple.com',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook / Server Notifications
    |--------------------------------------------------------------------------
    */
    'webhook' => [
        // URI where Apple sends server notifications.
        // Register this URL in App Store Connect > App Information > App Store Server Notifications.
        'path' => env('APPLE_IAP_WEBHOOK_PATH', '/webhooks/apple'),

        // Whether to process notifications on a queue (recommended for production).
        'queue_notifications' => env('APPLE_IAP_QUEUE_NOTIFICATIONS', false),
        'queue_connection'    => env('APPLE_IAP_QUEUE_CONNECTION', null),
        'queue_name'          => env('APPLE_IAP_QUEUE_NAME', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client
    |--------------------------------------------------------------------------
    */
    'http' => [
        'connect_timeout' => env('APPLE_IAP_CONNECT_TIMEOUT', 10),
        'timeout'         => env('APPLE_IAP_TIMEOUT', 30),
        'retry' => [
            'times' => env('APPLE_IAP_RETRY_TIMES', 3),
            'sleep' => env('APPLE_IAP_RETRY_SLEEP', 100), // milliseconds
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => env('APPLE_IAP_LOG_ENABLED', false),
        'channel' => env('APPLE_IAP_LOG_CHANNEL', null), // null = default channel
        'debug'   => env('APPLE_IAP_LOG_DEBUG', false),  // logs full request/response bodies
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    |
    | Wraps HTTP calls to Apple's APIs. When too many consecutive failures occur
    | the circuit opens and requests are rejected immediately (fail-fast) until
    | the recovery timeout elapses, at which point a probe request is allowed.
    |
    | States: closed (normal) → open (failing fast) → half-open (probe) → closed
    |
    */
    'circuit_breaker' => [
        'enabled'           => env('APPLE_IAP_CB_ENABLED', true),
        'failure_threshold' => env('APPLE_IAP_CB_FAILURES', 5),   // trips open after N failures
        'recovery_timeout'  => env('APPLE_IAP_CB_RECOVERY', 60),  // seconds before half-open probe
        'success_threshold' => env('APPLE_IAP_CB_SUCCESSES', 2),  // half-open → closed after N successes
        'cache_store'       => env('APPLE_IAP_CB_CACHE', null),   // null = default cache store
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Cache
    |--------------------------------------------------------------------------
    |
    | The App Store Server API JWT token is cached to avoid regenerating it
    | on every request. Adjust the TTL (seconds) and cache store as needed.
    |
    */
    'cache' => [
        'store' => env('APPLE_IAP_CACHE_STORE', null), // null = default cache store
        'token_ttl' => 3600, // seconds; token is regenerated 60s before expiry
    ],

];
