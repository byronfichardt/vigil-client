<?php

namespace Vigil\Client;

use Throwable;

/**
 * Adds up AI and API costs per provider during a request or job and sends them to Vigil in one call when it ends.
 */
class AiSpendRecorder
{
    /** Providers Vigil accepts; anything else would make it reject the whole batch. */
    public const PROVIDERS = ['gemini', 'serper', 'serpapi'];

    /** Long-running processes send early, well below Vigil's $1,000 limit per provider per call. */
    public const FLUSH_AFTER_RECORDS = 100;

    public const FLUSH_AT_USD = 100.0;

    /** @var array<string, float> */
    private array $costs = [];

    private int $records = 0;

    public function __construct(
        private readonly VigilClient $client,
        private readonly bool $enabled,
    ) {}

    public function record(string $provider, float $costUsd): void
    {
        $provider = strtolower($provider);

        if (! $this->enabled || ! is_finite($costUsd) || $costUsd <= 0 || ! in_array($provider, self::PROVIDERS, true)) {
            return;
        }

        $this->costs[$provider] = ($this->costs[$provider] ?? 0) + $costUsd;

        if (++$this->records >= self::FLUSH_AFTER_RECORDS || $this->costs[$provider] >= self::FLUSH_AT_USD) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->costs === []) {
            return;
        }

        $spend = array_map(fn (string $provider, float $cost) => ['provider' => $provider, 'cost_usd' => round($cost, 6)], array_keys($this->costs), $this->costs);
        $this->costs = [];
        $this->records = 0;

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
