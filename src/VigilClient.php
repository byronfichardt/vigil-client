<?php

namespace Vigil\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
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

    /** cURL could not resolve, reach, proxy to or open TLS to Vigil, so nothing was sent. */
    private const NOT_SENT_ERRNOS = [5, 6, 7, 35, 51, 58, 60];

    /** Vigil did not take the report: a wrong key, rate limited, no endpoint yet, or a server error (saved all-or-nothing). */
    private const RESEND_STATUSES = [401, 403, 404, 429];

    /**
     * @param  list<array{provider: string, cost_usd: float}>  $spend
     * @return bool false only when Vigil certainly did not record it, so it can be sent again
     */
    public function sendAiSpend(array $spend): bool
    {
        try {
            $response = $this->http->post(
                rtrim($this->url, '/').'/api/ai-spend',
                [
                    'headers' => $this->headers(),
                    'json' => ['spend' => $spend],
                    'allow_redirects' => false,
                ]
            );

            return $response->getStatusCode() < 300;
        } catch (Throwable $e) {
            if ($this->debug) {
                error_log('[Vigil] Failed to send AI spend: '.$e->getMessage());
            }

            return ! match (true) {
                $e instanceof BadResponseException => in_array($e->getResponse()->getStatusCode(), self::RESEND_STATUSES, true) || $e->getResponse()->getStatusCode() >= 500,
                $e instanceof ConnectException => ! method_exists($e, 'getHandlerContext') || $this->notStarted($e->getHandlerContext()),
                $e instanceof RequestException && method_exists($e, 'getHandlerContext') && ! $e->hasResponse() => $this->notStarted($e->getHandlerContext()),
                default => false,
            };
        }
    }

    /**
     * Guzzle 8 throws ConnectException only when no connection was made. Guzzle 7 also uses it for timeouts and
     * reports certificate and proxy failures as a plain RequestException, so there the cURL details decide.
     */
    private function notStarted(array $context): bool
    {
        return in_array($context['errno'] ?? null, self::NOT_SENT_ERRNOS, true) || (float) ($context['pretransfer_time'] ?? 1) === 0.0;
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
