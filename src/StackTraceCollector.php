<?php

namespace Vigil\Client;

use Throwable;

class StackTraceCollector
{
    private const MAX_FRAMES = 50;

    private const CONTEXT_LINES = 5;

    public function collect(Throwable $e): array
    {
        try {
            $frames = [];
            $trace = $e->getTrace();

            // Add the exception origin as the first frame
            $frames[] = $this->buildFrame([
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'class' => null,
                'function' => null,
            ]);

            foreach (array_slice($trace, 0, self::MAX_FRAMES - 1) as $entry) {
                $frames[] = $this->buildFrame($entry);
            }

            return $frames;
        } catch (Throwable) {
            return [];
        }
    }

    private function buildFrame(array $entry): array
    {
        $file = $entry['file'] ?? null;
        $line = $entry['line'] ?? null;

        return [
            'file' => $file,
            'line' => $line,
            'class' => $entry['class'] ?? null,
            'function' => $entry['function'] ?? null,
            'code_snippet' => $file && $line ? $this->getCodeSnippet($file, $line) : null,
        ];
    }

    private function getCodeSnippet(string $file, int $line): ?array
    {
        try {
            if (! is_file($file) || ! is_readable($file)) {
                return null;
            }

            $lines = file($file);

            if ($lines === false) {
                return null;
            }

            $start = max(0, $line - self::CONTEXT_LINES - 1);
            $end = min(count($lines), $line + self::CONTEXT_LINES);

            $snippet = [];

            for ($i = $start; $i < $end; $i++) {
                $snippet[$i + 1] = rtrim($lines[$i]);
            }

            return $snippet;
        } catch (Throwable) {
            return null;
        }
    }
}
