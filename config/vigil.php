<?php

return [
    'url' => env('VIGIL_URL'),
    'key' => env('VIGIL_KEY'),
    'enabled' => env('VIGIL_ENABLED', true),
    'environment' => env('APP_ENV', 'production'),

    // Don't report these exception classes
    'ignore' => [
        Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        Illuminate\Auth\AuthenticationException::class,
        Illuminate\Session\TokenMismatchException::class,
    ],
];
