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
    ) {
        $this->http = new Client([
            'timeout' => 5,
            'connect_timeout' => 5,
        ]);
    }

    public function send(array $payload): void
    {
        try {
            $this->http->postAsync(
                rtrim($this->url, '/').'/api/exceptions',
                [
                    'headers' => [
                        'X-Vigil-Key' => $this->key,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'json' => $payload,
                ]
            )->wait();
        } catch (Throwable) {
            // Silently fail - never affect the host application
        }
    }
}
