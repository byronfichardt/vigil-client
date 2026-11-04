<?php

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Vigil\Client\AiSpendRecorder;
use Vigil\Client\Facades\AiSpend;
use Vigil\Client\VigilClient;
use Vigil\Client\VigilLogFlusher;

it('adds up costs per provider and sends them in one call', function () {
    AiSpend::record('gemini', 0.0125);
    AiSpend::record('gemini', 0.0075);
    AiSpend::record('serper', 0.001);

    AiSpend::flush();

    expect($this->vigil->spendBatches)->toBe([[
        ['provider' => 'gemini', 'cost_usd' => 0.02],
        ['provider' => 'serper', 'cost_usd' => 0.001],
    ]]);
});

it('sends nothing when there is no spend', function () {
    AiSpend::record('gemini', 0);
    AiSpend::flush();

    expect($this->vigil->spendBatches)->toBeEmpty();
});

it('does not send the same spend twice', function () {
    AiSpend::record('gemini', 0.5);
    AiSpend::flush();
    AiSpend::flush();

    expect($this->vigil->spendBatches)->toHaveCount(1);
});

it('flushes spend when a queued job finishes', function () {
    AiSpend::record('gemini', 0.5);

    event(new JobProcessed('database', Mockery::mock(Illuminate\Contracts\Queue\Job::class)));

    expect($this->vigil->spendBatches)->toHaveCount(1);
});

it('flushes spend when a web request ends', function () {
    AiSpend::record('serpapi', 0.015);

    (new VigilLogFlusher)->terminate(request(), response('ok'));

    expect($this->vigil->spendBatches[0])->toBe([['provider' => 'serpapi', 'cost_usd' => 0.015]]);
});


it('flushes spend when a queued job fails', function () {
    AiSpend::record('gemini', 0.5);

    event(new JobFailed('database', Mockery::mock(Illuminate\Contracts\Queue\Job::class), new RuntimeException('boom')));

    expect($this->vigil->spendBatches)->toHaveCount(1);
});

it('drops providers Vigil does not accept so the rest still arrive', function () {
    AiSpend::record('openai', 1.0);
    AiSpend::record('Gemini', 0.25);
    AiSpend::record('serper', NAN);

    AiSpend::flush();

    expect($this->vigil->spendBatches)->toBe([[['provider' => 'gemini', 'cost_usd' => 0.25]]]);
});

it('sends early in long-running processes', function () {
    collect(range(1, AiSpendRecorder::FLUSH_AFTER_RECORDS))->each(fn () => AiSpend::record('serper', 0.001));

    expect($this->vigil->spendBatches)->toHaveCount(1)
        ->and(AiSpend::pending())->toBeEmpty();
});

it('sends early before a provider total gets near Vigil\'s limit', function () {
    AiSpend::record('gemini', AiSpendRecorder::FLUSH_AT_USD);

    expect($this->vigil->spendBatches)->toBe([[['provider' => 'gemini', 'cost_usd' => AiSpendRecorder::FLUSH_AT_USD]]]);
});

it('keeps spend that Vigil turned away and sends it next time', function () {
    $this->vigil->acceptSpend = false;
    AiSpend::record('gemini', 0.5);
    AiSpend::flush();

    expect(AiSpend::pending())->toBe(['gemini' => 0.5]);

    $this->vigil->acceptSpend = true;
    AiSpend::record('gemini', 0.25);
    AiSpend::flush();

    expect($this->vigil->spendBatches)->toBe([[['provider' => 'gemini', 'cost_usd' => 0.75]]]);
});

it('splits totals over Vigil\'s per-row limit', function () {
    AiSpend::record('gemini', 99);
    AiSpend::record('gemini', 950);

    expect($this->vigil->spendBatches)->toBe([[
        ['provider' => 'gemini', 'cost_usd' => 1000.0],
        ['provider' => 'gemini', 'cost_usd' => 49.0],
    ]]);
});

it('sends no more than twenty rows per call', function () {
    AiSpend::record('gemini', 25_000);

    expect(array_map('count', $this->vigil->spendBatches))->toBe([20, 5]);
});

it('sends from a busy queue worker at most every half minute', function () {
    AiSpend::record('gemini', 0.5);
    event(new JobProcessed('database', Mockery::mock(Illuminate\Contracts\Queue\Job::class)));
    AiSpend::record('gemini', 0.25);
    event(new JobProcessed('database', Mockery::mock(Illuminate\Contracts\Queue\Job::class)));

    expect($this->vigil->spendBatches)->toHaveCount(1)
        ->and(AiSpend::pending())->toBe(['gemini' => 0.25]);
});

it('sends spend before a timed-out job is killed', function () {
    AiSpend::record('gemini', 0.5);
    event(new JobProcessed('database', Mockery::mock(Illuminate\Contracts\Queue\Job::class)));
    AiSpend::record('gemini', 0.25);

    event(new Illuminate\Queue\Events\JobTimedOut('database', Mockery::mock(Illuminate\Contracts\Queue\Job::class)));

    expect($this->vigil->spendBatches)->toHaveCount(2);
});

it('flushes when a job throws and will be retried', function () {
    AiSpend::record('gemini', 0.5);

    event(new Illuminate\Queue\Events\JobExceptionOccurred('database', Mockery::mock(Illuminate\Contracts\Queue\Job::class), new RuntimeException('boom')));

    expect($this->vigil->spendBatches)->toHaveCount(1);
});

it('ignores spend when Vigil has no key', function () {
    config(['vigil.key' => null]);
    app()->forgetInstance(AiSpendRecorder::class);
    AiSpend::clearResolvedInstances();

    AiSpend::record('gemini', 1.0);

    expect(AiSpend::pending())->toBeEmpty();
});

it('only asks for a resend when Vigil certainly did not record the spend', function (callable $response, bool $recorded) {
    $client = new VigilClient('https://vigil.test', 'key', handler: GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler([$response])));

    expect($client->sendAiSpend([['provider' => 'gemini', 'cost_usd' => 1.0]]))->toBe($recorded);
})->with([
    'accepted' => [fn () => new GuzzleHttp\Psr7\Response(202), true],
    'rate limited' => [fn () => new GuzzleHttp\Psr7\Response(429), false],
    'rejected' => [fn () => new GuzzleHttp\Psr7\Response(422), true],
    'server error' => [fn () => new GuzzleHttp\Psr7\Response(500), true],
    'unreachable' => [fn () => new GuzzleHttp\Exception\ConnectException('refused', new GuzzleHttp\Psr7\Request('POST', 'https://vigil.test')), false],
    'timed out after sending' => [fn () => new GuzzleHttp\Exception\NetworkTimeoutException('timeout', new GuzzleHttp\Psr7\Request('POST', 'https://vigil.test')), true],
]);
