<?php

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

it('ignores spend when Vigil is not configured', function () {
    $recorder = new AiSpendRecorder(app(VigilClient::class), enabled: false);

    $recorder->record('gemini', 1.0);
    $recorder->flush();

    expect($recorder->pending())->toBeEmpty()
        ->and($this->vigil->spendBatches)->toBeEmpty();
});
