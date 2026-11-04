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
            if (config('vigil.logs.enabled', true)) {
                app(VigilLogHandler::class)->flush();
            }
        } catch (Throwable) {
        }

        try {
            app(AiSpendRecorder::class)->flush();
        } catch (Throwable) {
        }
    }
}
