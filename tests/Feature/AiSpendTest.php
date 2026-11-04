<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Vigil\Client\AiSpendRecorder;
use Vigil\Client\Facades\AiSpend;
use Vigil\Client\VigilClient;

function spendEvent(): Illuminate\Console\Scheduling\Event
{
    return collect(app(Schedule::class)->events())->firstWhere('description', 'vigil:ai-spend');
}

it('adds up costs per provider and sends them once a minute', function () {
    AiSpend::record('gemini', 0.0125);
    AiSpend::record('gemini', 0.0075);
    AiSpend::record('serper', 0.001);

    spendEvent()->run(app());

    expect(spendEvent()->expression)->toBe('* * * * *')
        ->and($this->vigil->spendBatches)->toBe([[
            ['provider' => 'gemini', 'cost_usd' => 0.02],
            ['provider' => 'serper', 'cost_usd' => 0.001],
        ]])
        ->and(AiSpend::pending())->toBeEmpty();
});

it('sends nothing when there is no spend', function () {
    AiSpend::record('gemini', 0);
    AiSpend::send();

    expect($this->vigil->spendBatches)->toBeEmpty();
});

it('does not send the same spend twice', function () {
    AiSpend::record('gemini', 0.5);
    AiSpend::send();
    AiSpend::send();

    expect($this->vigil->spendBatches)->toHaveCount(1);
});

it('keeps spend recorded while a send is under way', function () {
    AiSpend::record('gemini', 0.5);
    $this->vigil->duringSend = fn () => AiSpend::record('gemini', 0.25);

    AiSpend::send();

    expect(AiSpend::pending())->toBe(['gemini' => 0.25]);
});

it('drops providers Vigil does not accept so the rest still arrive', function () {
    AiSpend::record('openai', 1.0);
    AiSpend::record('Gemini', 0.25);
    AiSpend::record('serper', NAN);

    AiSpend::send();

    expect($this->vigil->spendBatches)->toBe([[['provider' => 'gemini', 'cost_usd' => 0.25]]]);
});

it('keeps spend that Vigil turned away and sends it next time', function () {
    $this->vigil->acceptSpend = false;
    AiSpend::record('gemini', 0.5);
    AiSpend::send();

    expect(AiSpend::pending())->toBe(['gemini' => 0.5]);

    $this->vigil->acceptSpend = true;
    AiSpend::record('gemini', 0.25);
    AiSpend::send();

    expect($this->vigil->spendBatches)->toBe([[['provider' => 'gemini', 'cost_usd' => 0.75]]]);
});

it('splits totals over Vigil\'s per-row limit', function () {
    AiSpend::record('gemini', 99);
    AiSpend::record('gemini', 950);
    AiSpend::send();

    expect($this->vigil->spendBatches)->toBe([[
        ['provider' => 'gemini', 'cost_usd' => 1000],
        ['provider' => 'gemini', 'cost_usd' => 49],
    ]]);
});

it('sends twenty rows per call and a few calls per minute at most', function () {
    AiSpend::record('gemini', 70_000);
    AiSpend::send();

    expect(array_map('count', $this->vigil->spendBatches))->toBe([20, 20, 20])
        ->and(AiSpend::pending())->toBe(['gemini' => 10_000.0]);
});

it('stops at the first turned-away call', function () {
    $this->vigil->acceptSpend = false;
    AiSpend::record('gemini', 25_000);
    AiSpend::send();

    expect($this->vigil->sendAttempts)->toBe(1)
        ->and(AiSpend::pending())->toBe(['gemini' => 25_000.0]);
});

it('ignores spend when Vigil has no key', function () {
    config(['vigil.key' => null]);
    app()->forgetInstance(AiSpendRecorder::class);
    AiSpend::clearResolvedInstances();

    AiSpend::record('gemini', 1.0);

    expect(AiSpend::pending())->toBeEmpty();
});

it('never lets a broken cache affect the app', function () {
    $cache = Mockery::mock(Illuminate\Contracts\Cache\Repository::class);
    $cache->shouldReceive('add')->andThrow(new RuntimeException('redis down'));
    $recorder = new AiSpendRecorder(app(VigilClient::class), $cache, enabled: true);

    $recorder->record('gemini', 1.0);
})->throwsNoExceptions();

it('only asks for a resend when Vigil certainly did not record the spend', function (callable $response, bool $recorded) {
    $client = new VigilClient('https://vigil.test', 'key', handler: GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler([$response])));

    expect($client->sendAiSpend([['provider' => 'gemini', 'cost_usd' => 1.0]]))->toBe($recorded);
})->with([
    'accepted' => [fn () => new GuzzleHttp\Psr7\Response(202), true],
    'rate limited' => [fn () => new GuzzleHttp\Psr7\Response(429), false],
    'rejected' => [fn () => new GuzzleHttp\Psr7\Response(422), true],
    'Vigil not upgraded yet' => [fn () => new GuzzleHttp\Psr7\Response(404), false],
    'wrong key' => [fn () => new GuzzleHttp\Psr7\Response(401), false],
    'server error' => [fn () => new GuzzleHttp\Psr7\Response(500), false],
    'unreachable' => [fn () => new GuzzleHttp\Exception\ConnectException('refused', new GuzzleHttp\Psr7\Request('POST', 'https://vigil.test')), false],
    'timed out after sending' => [fn () => new GuzzleHttp\Exception\NetworkTimeoutException('timeout', new GuzzleHttp\Psr7\Request('POST', 'https://vigil.test')), true],
]);

it('tells Guzzle 7 connection failures apart by whether anything was sent', function (int $errno, float $pretransfer, bool $recorded) {
    $request = new GuzzleHttp\Psr7\Request('POST', 'https://vigil.test');
    $failure = new class('failed', $request) extends GuzzleHttp\Exception\ConnectException
    {
        public array $context = [];

        public function getHandlerContext(): array
        {
            return $this->context;
        }
    };
    $failure->context = ['errno' => $errno, 'pretransfer_time' => $pretransfer];
    $client = new VigilClient('https://vigil.test', 'key', handler: GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler([$failure])));

    expect($client->sendAiSpend([['provider' => 'gemini', 'cost_usd' => 1.0]]))->toBe($recorded);
})->with([
    'host not found' => [6, 0.0, false],
    'connection refused' => [7, 0.0, false],
    'TLS failed' => [35, 0.0, false],
    'timed out while connecting' => [28, 0.0, false],
    'timed out after sending' => [28, 0.12, true],
]);

it('runs one send at a time', function () {
    AiSpend::record('gemini', 0.5);
    $lock = Cache::lock('vigil:ai-spend:send', 120);
    $lock->get();

    AiSpend::send();
    expect($this->vigil->spendBatches)->toBeEmpty();

    $lock->release();
    AiSpend::send();
    expect($this->vigil->spendBatches)->toHaveCount(1);
});
