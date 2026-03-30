<?php

namespace Vigil\Client;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

class VigilLogHandler extends AbstractProcessingHandler
{
    private array $buffer = [];

    private bool $flushing = false;

    public function __construct(
        private readonly VigilClient $client,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if ($this->flushing) {
            return;
        }

        if (! $this->shouldCapture($record)) {
            return;
        }

        $this->buffer[] = [
            'level' => strtolower($record->level->name),
            'channel' => $record->channel,
            'message' => $record->message,
            'context' => $this->sanitizeContext($record->context),
            'extra' => $record->extra ?: null,
            'logged_at' => $record->datetime->format('c'),
        ];

        if (count($this->buffer) >= config('vigil.logs.buffer_limit', 100)) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if (empty($this->buffer) || $this->flushing) {
            return;
        }

        $this->flushing = true;

        try {
            $this->client->sendLogs($this->buffer);
        } catch (Throwable) {
        } finally {
            $this->buffer = [];
            $this->flushing = false;
        }
    }

    public function getBuffer(): array
    {
        return $this->buffer;
    }

    private function shouldCapture(LogRecord $record): bool
    {
        $allowedLevels = config('vigil.logs.levels', []);

        if (! empty($allowedLevels) && ! in_array(strtolower($record->level->name), $allowedLevels)) {
            return false;
        }

        $allowedChannels = config('vigil.logs.channels', ['*']);

        if ($allowedChannels !== ['*'] && ! in_array($record->channel, $allowedChannels)) {
            return false;
        }

        return true;
    }

    private function sanitizeContext(array $context): ?array
    {
        if (empty($context)) {
            return null;
        }

        unset($context['exception']);

        $redactFields = config('vigil.redact_fields', []);

        foreach ($context as $key => $value) {
            if (in_array(strtolower($key), array_map('strtolower', $redactFields))) {
                $context[$key] = '[REDACTED]';
            }
        }

        return empty($context) ? null : $context;
    }
}
