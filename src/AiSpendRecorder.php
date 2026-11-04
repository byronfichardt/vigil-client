<?php

namespace Vigil\Client;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * Adds up AI and API costs per provider in the app's cache, and sends the totals to Vigil once a minute
 * from the scheduler. A counter only goes down by what Vigil accepted, so nothing is lost between sends,
 * when a process is killed, or while Vigil is unreachable. Counting is atomic on the redis, database and
 * memcached stores; the file store can lose a cost when two processes record at the same moment.
 */
class AiSpendRecorder
{
    /** Providers Vigil accepts; anything else would make it reject the whole report. */
    public const PROVIDERS = ['gemini', 'serper', 'serpapi'];

    /** Vigil's limit per row; larger totals are split across rows. */
    public const MAX_ROW_USD = 1000;

    public const MAX_ROWS = 20;

    /** Calls per send; anything beyond waits a minute for the next send. */
    public const MAX_CALLS_PER_SEND = 3;

    /** Costs are counted in millionths of a dollar so the cache can add them atomically. */
    private const MICROS = 1_000_000;

    public function __construct(
        private readonly VigilClient $client,
        private readonly Repository $cache,
        private readonly bool $enabled,
    ) {}

    public function record(string $provider, float $costUsd): void
    {
        $provider = strtolower($provider);
        $micros = is_finite($costUsd) ? (int) round($costUsd * self::MICROS) : 0;

        if (! $this->enabled || $micros <= 0 || ! in_array($provider, self::PROVIDERS, true)) {
            return;
        }

        try {
            $this->cache->add(self::key($provider), 0);
            $this->cache->increment(self::key($provider), $micros);
        } catch (Throwable) {
        }
    }

    /**
     * Only one send runs at a time, even across servers, so the same spend is never reported twice.
     */
    public function send(): void
    {
        $store = $this->cache->getStore();

        if ($store instanceof LockProvider) {
            $store->lock('vigil:ai-spend:send', 120)->get(fn () => $this->sendPending());

            return;
        }

        $this->sendPending();
    }

    private function sendPending(): void
    {
        $rows = $this->rows();

        foreach (array_slice(array_chunk($rows, self::MAX_ROWS), 0, self::MAX_CALLS_PER_SEND) as $chunk) {
            $report = array_map(fn (array $row) => ['provider' => $row['provider'], 'cost_usd' => $row['micros'] / self::MICROS], $chunk);

            if (! $this->client->sendAiSpend($report)) {
                return;
            }

            foreach ($chunk as $row) {
                $this->cache->decrement(self::key($row['provider']), $row['micros']);
            }
        }
    }

    /**
     * @return array<string, float> cost waiting to be sent, per provider
     */
    public function pending(): array
    {
        return array_filter(array_combine(
            self::PROVIDERS,
            array_map(fn (string $provider) => (int) $this->cache->get(self::key($provider), 0) / (float) self::MICROS, self::PROVIDERS),
        ));
    }

    /**
     * @return list<array{provider: string, micros: int}>
     */
    private function rows(): array
    {
        $rows = [];

        foreach (self::PROVIDERS as $provider) {
            for ($left = (int) $this->cache->get(self::key($provider), 0); $left > 0; $left -= self::MAX_ROW_USD * self::MICROS) {
                $rows[] = ['provider' => $provider, 'micros' => min($left, self::MAX_ROW_USD * self::MICROS)];
            }
        }

        return $rows;
    }

    private static function key(string $provider): string
    {
        return "vigil:ai-spend:{$provider}";
    }
}
