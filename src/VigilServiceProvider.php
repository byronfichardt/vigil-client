<?php

namespace Vigil\Client;

use Illuminate\Contracts\Debug\ExceptionHandler;
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
            );
        });

        $this->app->singleton(StackTraceCollector::class);

        $this->app->singleton(ExceptionReporter::class, function ($app) {
            return new ExceptionReporter(
                client: $app->make(VigilClient::class),
                stackTraceCollector: $app->make(StackTraceCollector::class),
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

        try {
            $this->app->make(ExceptionHandler::class)
                ->reportable(function (Throwable $e) {
                    $this->app->make(ExceptionReporter::class)->report($e);
                })
                ->stop(false);
        } catch (Throwable) {
            // Silently fail - never interfere with the host application
        }
    }
}
