<?php

namespace Vigil\Client;

use GuzzleHttp\Client;
use Throwable;

class VigilClient
{
    private Client $http;

    public function __construct(
        private readonly ?string $url,
        private readonly ?string $key,
        int $timeout = 2,
        int $connectTimeout = 1,
        private readonly bool $debug = false,
    ) {
        $this->http = new Client([
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
        ]);
    }

    public function send(array $payload): void
    {
        try {
            $this->http->post(
                rtrim($this->url, '/').'/api/exceptions',
                [
                    'headers' => $this->headers(),
                    'json' => $payload,
                ]
            );
        } catch (Throwable $e) {
            if ($this->debug) {
                error_log('[Vigil] Failed to send exception: '.$e->getMessage());
            }
        }
    }

    public function sendLogs(array $logs): void
    {
        try {
            $payload = [
                'logs' => $logs,
                'environment' => config('vigil.environment'),
                'hostname' => gethostname(),
            ];

            if (! app()->runningInConsole() && request()) {
                $payload['request_url'] = request()->fullUrl();
                $payload['request_method'] = request()->method();
            }

            $this->http->post(
                rtrim($this->url, '/').'/api/logs',
                [
                    'headers' => $this->headers(),
                    'json' => $payload,
                ]
            );
        } catch (Throwable $e) {
            if ($this->debug) {
                error_log('[Vigil] Failed to send logs: '.$e->getMessage());
            }
        }
    }

    private function headers(): array
    {
        return [
            'X-Vigil-Key' => $this->key,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }
}
