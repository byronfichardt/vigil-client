<?php

return [
    'url' => env('VIGIL_URL'),
    'key' => env('VIGIL_KEY'),
    'enabled' => env('VIGIL_ENABLED', true),
    'environment' => env('APP_ENV', 'production'),
    'debug' => env('VIGIL_DEBUG', false),
    'timeout' => env('VIGIL_TIMEOUT', 2),
    'connect_timeout' => env('VIGIL_CONNECT_TIMEOUT', 1),
    'code_snippets' => true,

    'redact_fields' => [
        'password',
        'password_confirmation',
        'secret',
        'token',
        'credit_card',
        'card_number',
        'cvv',
        'ssn',
        'api_key',
        'authorization',
    ],

    'ignore' => [
        Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        Illuminate\Auth\AuthenticationException::class,
        Illuminate\Session\TokenMismatchException::class,
    ],

    'logs' => [
        'enabled' => env('VIGIL_LOGS_ENABLED', true),
        'levels' => ['warning', 'error', 'critical', 'alert', 'emergency'],
        'channels' => ['*'],
        'buffer_limit' => 100,
    ],
];
