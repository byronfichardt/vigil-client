<?php

namespace Vigil\Client\Tests;

use Vigil\Client\VigilClient;

class FakeVigilClient extends VigilClient
{
    public array $exceptions = [];

    public array $logBatches = [];

    public array $heartbeats = [];

    public function __construct()
    {
        parent::__construct(url: 'https://vigil.test', key: 'test-key');
    }

    public function send(array $payload): void
    {
        $this->exceptions[] = $payload;
    }

    public function sendLogs(array $logs): void
    {
        $this->logBatches[] = $logs;
    }

    public function heartbeat(int $intervalMinutes): void
    {
        $this->heartbeats[] = $intervalMinutes;
    }
}
