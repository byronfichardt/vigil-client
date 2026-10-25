<?php

use Illuminate\Support\Facades\Log;
use Vigil\Client\VigilLogHandler;

function flushLogs(): void
{
    app(VigilLogHandler::class)->flush();
}

it('forwards warnings and above', function () {
    Log::warning('Disk almost full', ['free_mb' => 120]);
    Log::error('Payment failed');

    flushLogs();

    $batch = $this->vigil->logBatches[0];

    expect($batch)->toHaveCount(2)
        ->and($batch[0])->level->toBe('warning')->message->toBe('Disk almost full')->context->toBe(['free_mb' => 120])
        ->and($batch[1])->level->toBe('error');
});

it('does not forward info or debug lines', function () {
    Log::info('User logged in');
    Log::debug('Cache hit');

    flushLogs();

    expect($this->vigil->logBatches)->toBeEmpty();
});

it('forwards the log line Laravel writes for a reported exception', function () {
    report(new RuntimeException('Payment gateway timed out'));

    flushLogs();

    expect($this->vigil->logBatches[0][0])
        ->level->toBe('error')
        ->message->toBe('Payment gateway timed out');
});

it('redacts sensitive context and drops the exception object', function () {
    Log::error('Login failed', ['password' => 'hunter2', 'email' => 'a@b.test', 'exception' => new RuntimeException('x')]);

    flushLogs();

    expect($this->vigil->logBatches[0][0]['context'])->toBe(['password' => '[REDACTED]', 'email' => 'a@b.test']);
});

it('flushes on its own when the buffer is full', function () {
    config(['vigil.logs.buffer_limit' => 3]);

    collect(range(1, 3))->each(fn ($i) => Log::warning("Warning {$i}"));

    expect($this->vigil->logBatches)->toHaveCount(1)
        ->and($this->vigil->logBatches[0])->toHaveCount(3);
});
