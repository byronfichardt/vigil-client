<?php

namespace Vigil\Client;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
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

        $this->app->singleton(AiSpendRecorder::class, fn ($app) => new AiSpendRecorder(
            client: $app->make(VigilClient::class),
            enabled: self::configured(),
        ));

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

        if (! self::configured()) {
            return;
        }

        $this->bootExceptionReporting();
        $this->bootLogForwarding();
        $this->bootFlushing();
        $this->bootHeartbeat();
    }

    private static function configured(): bool
    {
        return config('vigil.enabled') && config('vigil.url') && config('vigil.key');
    }

    private function bootExceptionReporting(): void
    {
        try {
            $this->app->make(ExceptionHandler::class)
                ->reportable(function (Throwable $e) {
                    $this->app->make(ExceptionReporter::class)->report($e);
                });
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
        } catch (Throwable) {
        }
    }

    /**
     * Buffered logs and AI spend are sent when a request, job or console command ends.
     */
    private function bootFlushing(): void
    {
        try {
            if ($this->app->runningInConsole()) {
                register_shutdown_function(fn () => VigilLogFlusher::flush());

                $events = $this->app->make('events');
                $events->listen([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class, Looping::class], fn () => VigilLogFlusher::flush(job: true));
                $events->listen(WorkerStopping::class, fn () => VigilLogFlusher::flush());

                if (class_exists(JobTimedOut::class)) {
                    $events->listen(JobTimedOut::class, fn () => VigilLogFlusher::flush());
                }
            } else {
                $this->app->make(HttpKernel::class)->pushMiddleware(VigilLogFlusher::class);
            }
        } catch (Throwable) {
        }
    }

    private function bootHeartbeat(): void
    {
        if (! config('vigil.heartbeat.enabled', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $minutes = (int) config('vigil.heartbeat.interval_minutes', 5);

            $schedule->call(fn () => $this->app->make(VigilClient::class)->heartbeat($minutes))
                ->name('vigil:heartbeat')
                ->cron("*/{$minutes} * * * *");
        });
    }
}
