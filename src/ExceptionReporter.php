<?php

namespace Vigil\Client;

use Throwable;

class ExceptionReporter
{
    private const MAX_REQUEST_BODY_SIZE = 16384;

    public function __construct(
        private readonly VigilClient $client,
        private readonly StackTraceCollector $stackTraceCollector,
    ) {}

    public function report(Throwable $e): void
    {
        try {
            if ($this->shouldIgnore($e)) {
                return;
            }

            $payload = [
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'stack_trace' => $this->stackTraceCollector->collect($e),
                'environment' => config('vigil.environment'),
                'hostname' => gethostname(),
                'app_version' => config('app.version'),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'occurred_at' => now()->toIso8601String(),
            ];

            if ($this->isRunningInHttpContext()) {
                $request = request();

                $payload['request_url'] = $request->fullUrl();
                $payload['request_method'] = $request->method();
                $payload['request_headers'] = $this->filterHeaders($request->headers->all());
                $payload['request_body'] = $this->filterRequestBody($request->all());
                $payload['request_query_params'] = $request->query();
            }

            $payload['user_info'] = $this->collectUserInfo();

            $this->client->send($payload);
        } catch (Throwable) {
        }
    }

    private function shouldIgnore(Throwable $e): bool
    {
        $ignoredClasses = config('vigil.ignore', []);

        foreach ($ignoredClasses as $ignoredClass) {
            if ($e instanceof $ignoredClass) {
                return true;
            }
        }

        return false;
    }

    private function isRunningInHttpContext(): bool
    {
        try {
            return app()->runningInConsole() === false && request() !== null;
        } catch (Throwable) {
            return false;
        }
    }

    private function filterHeaders(array $headers): array
    {
        $sensitiveHeaders = [
            'authorization',
            'cookie',
            'set-cookie',
            'x-csrf-token',
            'x-xsrf-token',
            'proxy-authorization',
        ];

        return array_filter(
            $headers,
            fn (string $key) => ! in_array(strtolower($key), $sensitiveHeaders),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function filterRequestBody(array $body): array
    {
        $redactFields = config('vigil.redact_fields', []);

        $filtered = array_map(function ($value, $key) use ($redactFields) {
            if (in_array(strtolower($key), array_map('strtolower', $redactFields))) {
                return '[REDACTED]';
            }

            return $value;
        }, $body, array_keys($body));

        $filtered = array_combine(array_keys($body), $filtered);

        $encoded = json_encode($filtered);
        $size = strlen($encoded);

        if ($size > self::MAX_REQUEST_BODY_SIZE) {
            return ['_truncated' => true, '_size' => $size];
        }

        return $filtered;
    }

    private function collectUserInfo(): ?array
    {
        try {
            $user = auth()->user();

            if (! $user) {
                return null;
            }

            return [
                'id' => $user->getAuthIdentifier(),
                'email' => $user->email ?? null,
            ];
        } catch (Throwable) {
            return null;
        }
    }
}
