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
        self::flush();
    }

    /**
     * Sends buffered logs and AI spend; queue workers pass $job so spend goes out at most every half minute.
     */
    public static function flush(bool $job = false): void
    {
        try {
            if (config('vigil.logs.enabled', true)) {
                app(VigilLogHandler::class)->flush();
            }
        } catch (Throwable) {
        }

        try {
            $job ? app(AiSpendRecorder::class)->flushIfDue() : app(AiSpendRecorder::class)->flush();
        } catch (Throwable) {
        }
    }
}
