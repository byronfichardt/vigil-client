<?php

namespace Vigil\Client;

use Throwable;

/**
 * Adds up AI and API costs per provider during a request or job and sends them to Vigil in one call when it ends.
 */
class AiSpendRecorder
{
    /** @var array<string, float> */
    private array $costs = [];

    public function __construct(
        private readonly VigilClient $client,
        private readonly bool $enabled,
    ) {}

    public function record(string $provider, float $costUsd): void
    {
        if (! $this->enabled || $costUsd <= 0) {
            return;
        }

        $this->costs[$provider] = ($this->costs[$provider] ?? 0) + $costUsd;
    }

    public function flush(): void
    {
        if ($this->costs === []) {
            return;
        }

        $spend = array_map(fn (string $provider, float $cost) => ['provider' => $provider, 'cost_usd' => round($cost, 6)], array_keys($this->costs), $this->costs);
        $this->costs = [];

        try {
            $this->client->sendAiSpend($spend);
        } catch (Throwable) {
        }
    }

    /**
     * @return array<string, float>
     */
    public function pending(): array
    {
        return $this->costs;
    }
}
