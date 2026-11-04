<?php

namespace Vigil\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\HandlerStack;
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
        ?HandlerStack $handler = null,
    ) {
        $this->http = new Client([
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
            'handler' => $handler ?? HandlerStack::create(),
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

    /** cURL could not resolve or reach Vigil, so nothing was sent. */
    private const NOT_SENT_ERRNOS = [6, 7];

    /**
     * @param  list<array{provider: string, cost_usd: float}>  $spend
     * @return bool false only when Vigil certainly did not record it, so it can be sent again: rate limited, endpoint
     *              missing (Vigil not upgraded yet), a server error (Vigil saves a report all-or-nothing) or unreachable
     */
    public function sendAiSpend(array $spend): bool
    {
        try {
            $this->http->post(
                rtrim($this->url, '/').'/api/ai-spend',
                [
                    'headers' => $this->headers(),
                    'json' => ['spend' => $spend],
                ]
            );

            return true;
        } catch (Throwable $e) {
            if ($this->debug) {
                error_log('[Vigil] Failed to send AI spend: '.$e->getMessage());
            }

            return ! match (true) {
                $e instanceof BadResponseException => in_array($e->getResponse()->getStatusCode(), [404, 429], true) || $e->getResponse()->getStatusCode() >= 500,
                $e instanceof ConnectException => $this->neverSent($e),
                default => false,
            };
        }
    }

    /**
     * Guzzle 8 throws ConnectException only when no connection was made; Guzzle 7 also throws it for timeouts after sending.
     */
    private function neverSent(ConnectException $e): bool
    {
        return ! method_exists($e, 'getHandlerContext') || in_array($e->getHandlerContext()['errno'] ?? null, self::NOT_SENT_ERRNOS, true);
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
