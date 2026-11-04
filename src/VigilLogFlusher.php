<?php

namespace Vigil\Client;

use Closure;
use Throwable;

class VigilLogFlusher
{
    public function handle($request, Closure $next)
    {
        return $next($request);
    }

    public function terminate($request, $response): void
    {
        try {
            app(VigilLogHandler::class)->flush();
        } catch (Throwable) {
        }
    }
}
