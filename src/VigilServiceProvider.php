<?php

namespace Vigil\Client;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Throwable;

class VigilServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vigil.php', 'vigil');

        $this->app->singleton(VigilClient::class, function ($app) {
            return new VigilClient(
                url: config('vigil.url'),
                key: config('vigil.key'),
                timeout: (int) config('vigil.timeout', 2),
                connectTimeout: (int) config('vigil.connect_timeout', 1),
                debug: (bool) config('vigil.debug', false),
            );
        });

        $this->app->singleton(StackTraceCollector::class);

        $this->app->singleton(ExceptionReporter::class, function ($app) {
            return new ExceptionReporter(
                client: $app->make(VigilClient::class),
                stackTraceCollector: $app->make(StackTraceCollector::class),
            );
        });

        $this->app->singleton(VigilLogHandler::class, function ($app) {
            return new VigilLogHandler(
                client: $app->make(VigilClient::class),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/vigil.php' => config_path('vigil.php'),
        ], 'vigil-config');

        if (! config('vigil.enabled') || ! config('vigil.url') || ! config('vigil.key')) {
            return;
        }

        $this->bootExceptionReporting();
        $this->bootLogForwarding();
    }

    private function bootExceptionReporting(): void
    {
        try {
            $this->app->make(ExceptionHandler::class)
                ->reportable(function (Throwable $e) {
                    $this->app->make(ExceptionReporter::class)->report($e);
                })
                ->stop(false);
        } catch (Throwable) {
        }
    }

    private function bootLogForwarding(): void
    {
        if (! config('vigil.logs.enabled', true)) {
            return;
        }

        try {
            $handler = $this->app->make(VigilLogHandler::class);

            $this->app->booted(function () use ($handler) {
                try {
                    Log::getLogger()->pushHandler($handler);
                } catch (Throwable) {
                }
            });

            if ($this->app->runningInConsole()) {
                register_shutdown_function(fn () => $handler->flush());

                $this->app->make('events')->listen(JobProcessed::class, fn () => $handler->flush());
                $this->app->make('events')->listen(JobFailed::class, fn () => $handler->flush());
            } else {
                $this->app->make(HttpKernel::class)->pushMiddleware(VigilLogFlusher::class);
            }
        } catch (Throwable) {
        }
    }
}
