<?php

namespace Vigil\Client;

/**
 * Adds up AI and API costs per provider and sends them to Vigil when a request or command ends,
 * and at most every half minute from a queue worker. Spend Vigil turned away is kept for the next send,
 * which only helps long-running processes: a PHP-FPM request loses it when the request ends.
 */
class AiSpendRecorder
{
    /** Providers Vigil accepts; anything else would make it reject the whole batch. */
    public const PROVIDERS = ['gemini', 'serper', 'serpapi'];

    public const FLUSH_AFTER_RECORDS = 100;

    /** Long-running processes send early once a provider's total reaches this. */
    public const FLUSH_AT_USD = 100.0;

    /** Vigil's limit per row; larger totals are split across rows. */
    public const MAX_ROW_USD = 1000.0;

    public const MAX_ROWS = 20;

    /** Calls per flush; anything beyond waits for the next flush instead of blocking the request. */
    public const MAX_CALLS_PER_FLUSH = 3;

    public const JOB_FLUSH_SECONDS = 30;

    /** @var array<string, float> */
    private array $costs = [];

    private int $records = 0;

    private ?int $flushedAtMs = null;

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
            $this->flushIfDue();
        }
    }

    /**
     * Sends at most every JOB_FLUSH_SECONDS, so a busy worker or a rate-limited retry stays under Vigil's rate limit.
     */
    public function flushIfDue(): void
    {
        if ($this->flushedAtMs === null || now()->getTimestampMs() - $this->flushedAtMs >= self::JOB_FLUSH_SECONDS * 1000) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        $this->flushedAtMs = now()->getTimestampMs();

        if ($this->costs === []) {
            return;
        }

        $chunks = array_chunk($this->rows($this->costs), self::MAX_ROWS);
        $this->costs = [];
        $this->records = 0;

        foreach ($chunks as $i => $rows) {
            if ($i >= self::MAX_CALLS_PER_FLUSH || ! $this->client->sendAiSpend($rows)) {
                $this->keep(array_merge(...array_slice($chunks, $i)));

                return;
            }
        }
    }

    /**
     * @param  list<array{provider: string, cost_usd: float}>  $rows
     */
    private function keep(array $rows): void
    {
        foreach ($rows as $row) {
            $this->costs[$row['provider']] = ($this->costs[$row['provider']] ?? 0) + $row['cost_usd'];
        }
    }

    /**
     * @return array<string, float>
     */
    public function pending(): array
    {
        return $this->costs;
    }

    /**
     * @param  array<string, float>  $costs
     * @return list<array{provider: string, cost_usd: float}>
     */
    private function rows(array $costs): array
    {
        $rows = [];

        foreach ($costs as $provider => $cost) {
            for ($left = $cost; $left > 0; $left -= self::MAX_ROW_USD) {
                $rows[] = ['provider' => $provider, 'cost_usd' => round(min($left, self::MAX_ROW_USD), 6)];
            }
        }

        return $rows;
    }
}
