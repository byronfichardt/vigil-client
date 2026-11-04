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

    public function heartbeat(int $intervalMinutes): void
    {
        try {
            $this->http->post(
                rtrim($this->url, '/').'/api/heartbeat',
                [
                    'headers' => $this->headers(),
                    'json' => ['interval_minutes' => $intervalMinutes],
                ]
            );
        } catch (Throwable $e) {
            if ($this->debug) {
                error_log('[Vigil] Failed to send heartbeat: '.$e->getMessage());
            }
        }
    }

    /**
     * @param  list<array{provider: string, cost_usd: float}>  $spend
     */
    public function sendAiSpend(array $spend): void
    {
        try {
            $this->http->post(
                rtrim($this->url, '/').'/api/ai-spend',
                [
                    'headers' => $this->headers(),
                    'json' => ['spend' => $spend],
                ]
            );
        } catch (Throwable $e) {
            if ($this->debug) {
                error_log('[Vigil] Failed to send AI spend: '.$e->getMessage());
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
